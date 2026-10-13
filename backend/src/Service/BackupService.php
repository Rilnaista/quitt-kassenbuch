<?php

declare(strict_types=1);

namespace Quitt\Service;

use Psr\Log\LoggerInterface;
use Quitt\App\Config;
use Quitt\Db\Database;
use Quitt\Support\Clock;
use Quitt\Support\Time;

/**
 * Sicherungen als ZIP (quitt-backup/1).
 *
 * Inhalt: manifest.json, database.sqlite (konsistente Kopie per VACUUM INTO, OHNE Anmeldungen/Tickets/
 * Drosselung), app-settings.json (Einstellungen inkl. ai_key), receipts/<uuid> (Belegdateien).
 * Das ZIP enthaelt PIN-/Recovery-Hash und KI-Schluessel -> wie ein Passwort behandeln.
 * Automatische Sicherungen: <data>/backups/auto-YYYYMMDD-HHMMSS-<grund>[-n].zip, Rotation backup_keep (10).
 */
class BackupService
{
    public const FORMAT = 'quitt-backup/1';
    public const REASON_MANUAL = 'manual';
    public const REASON_BEFORE_UPDATE = 'before_update';
    public const REASON_BEFORE_RESTORE = 'before_restore';
    public const REASON_BEFORE_MIGRATION = 'before_migration';
    public const REASONS = [self::REASON_MANUAL, self::REASON_BEFORE_UPDATE, self::REASON_BEFORE_RESTORE, self::REASON_BEFORE_MIGRATION];
    public const NAME_PATTERN = '/^auto-(\d{8})-(\d{6})-(manual|before_update|before_restore|before_migration)(?:-\d+)?\.zip$/';
    /** Tabellen, die NICHT in die Sicherung gehoeren (geraetebezogen/fluechtig) */
    public const VOLATILE_TABLES = ['auth_tokens', 'download_tickets', 'login_attempts'];

    public function __construct(
        protected readonly Config $config,
        protected readonly Database $db,
        protected readonly Clock $clock,
        protected readonly LoggerInterface $log,
    ) {
    }

    public function dir(): string
    {
        return $this->config->path('backups');
    }

    /** Legt eine automatische Sicherung (ZIP) an und raeumt alte auf. @return string Pfad der Sicherung */
    public function autoBackup(string $reason): string
    {
        if (!in_array($reason, self::REASONS, true)) {
            throw new \InvalidArgumentException('Unbekannter Sicherungsgrund: ' . $reason);
        }
        $dir = $this->dir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $stamp = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Ymd-His');
        $target = sprintf('%s/auto-%s-%s.zip', $dir, $stamp, $reason);
        $i = 1;
        while (file_exists($target)) {
            $target = sprintf('%s/auto-%s-%s-%d.zip', $dir, $stamp, $reason, $i++);
        }
        $this->createArchive($target, $reason);
        $this->prune();
        $this->log->info('auto backup created', ['file' => basename($target), 'reason' => $reason]);
        return $target;
    }

    /**
     * Schreibt das Sicherungs-ZIP nach $target.
     * @return array<string, mixed> manifest
     */
    public function createArchive(string $target, string $reason = self::REASON_MANUAL): array
    {
        if (!is_file($this->db->file())) {
            throw new \RuntimeException('Keine Datenbank zum Sichern vorhanden');
        }
        $dbCopy = $this->tempPath('db');
        $tmpZip = $target . '.part';
        try {
            $this->copyDatabase($dbCopy);
            $this->stripVolatile($dbCopy);

            $receipts = $this->receiptFiles();
            $entries = $this->countEntries($dbCopy);
            $manifest = [
                'format' => self::FORMAT,
                'app_version' => $this->config->version(),
                'schema_version' => $this->schemaVersion($dbCopy),
                'created_at' => Time::iso($this->clock->now()),
                'reason' => $reason,
                'entries' => $entries,
                'receipts' => count($receipts),
            ];
            $zip = new \ZipArchive();
            if ($zip->open($tmpZip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Sicherung nicht schreibbar');
            }
            $zip->addFromString('manifest.json', json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            $zip->addFile($dbCopy, 'database.sqlite');
            $zip->addFromString('app-settings.json', json_encode($this->settingsRaw() ?: new \stdClass(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            foreach ($receipts as $name => $path) {
                $zip->addFile($path, 'receipts/' . $name);
            }
            if (!$zip->close()) {
                throw new \RuntimeException('Sicherung konnte nicht geschrieben werden');
            }
            if (!@rename($tmpZip, $target)) {
                throw new \RuntimeException('Sicherung konnte nicht abgelegt werden');
            }
            return $manifest;
        } finally {
            @unlink($dbCopy);
            @unlink($tmpZip);
        }
    }

    /** Behaelt die neuesten backup_keep automatischen Sicherungen. */
    public function prune(): void
    {
        $keep = max(1, (int) $this->config->get('backup_keep', 10));
        $files = glob($this->dir() . '/auto-*') ?: [];
        sort($files, SORT_STRING);
        while (count($files) > $keep) {
            @unlink(array_shift($files));
        }
    }

    /** @return list<array{name: string, created_at: string, size: int, reason: string}> neueste zuerst */
    public function list(): array
    {
        $out = [];
        foreach (glob($this->dir() . '/auto-*.zip') ?: [] as $file) {
            $name = basename($file);
            if (!preg_match(self::NAME_PATTERN, $name, $m)) {
                continue;
            }
            $t = \DateTimeImmutable::createFromFormat('Ymd His', $m[1] . ' ' . $m[2], new \DateTimeZone('UTC'));
            $out[] = [
                'name' => $name,
                'created_at' => $t ? Time::iso($t) : '',
                'size' => (int) filesize($file),
                'reason' => $m[3],
            ];
        }
        usort($out, fn ($a, $b) => strcmp($b['name'], $a['name']));
        return $out;
    }

    /** Streng validierter Name -> Pfad; null wenn Name ungueltig oder Datei fehlt (kein Pfad-Traversal). */
    public function pathFor(string $name): ?string
    {
        if (!preg_match(self::NAME_PATTERN, $name)) {
            return null;
        }
        $path = $this->dir() . '/' . $name;
        return is_file($path) ? $path : null;
    }

    /** Temporaerer Pfad im Datenordner (gesperrt per .htaccess), Aufraeumen durch den Aufrufer. */
    public function tempPath(string $kind): string
    {
        return $this->config->path('.tmp-' . $kind . '-' . bin2hex(random_bytes(6)));
    }

    /** Aeltere Reste von abgebrochenen Vorgaengen entfernen (> 1 h). */
    public function cleanupTemp(): void
    {
        foreach (glob($this->config->path('.tmp-*')) ?: [] as $p) {
            if ((int) @filemtime($p) < $this->clock->now()->getTimestamp() - 3600) {
                is_dir($p) ? self::rmrf($p) : @unlink($p);
            }
        }
    }

    public static function rmrf(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $p = $dir . '/' . $f;
            is_dir($p) && !is_link($p) ? self::rmrf($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    /** @return array<string, string> Dateiname => Pfad (nur uuid-benannte Dateien) */
    protected function receiptFiles(): array
    {
        $out = [];
        $dir = $this->config->path('receipts');
        foreach (is_dir($dir) ? (scandir($dir) ?: []) : [] as $name) {
            if (preg_match('/^[0-9a-f-]{36}$/', $name) && is_file($dir . '/' . $name)) {
                $out[$name] = $dir . '/' . $name;
            }
        }
        return $out;
    }

    /** @return array<string, mixed> */
    protected function settingsRaw(): array
    {
        $out = [];
        if ($this->db->tableExists('settings')) {
            foreach ($this->db->all('SELECT key, value FROM settings ORDER BY key') as $row) {
                $out[$row['key']] = json_decode((string) $row['value'], true);
            }
        }
        return $out;
    }

    protected function copyDatabase(string $target): void
    {
        try {
            $this->db->pdo()->exec('VACUUM INTO ' . $this->db->pdo()->quote($target));
        } catch (\PDOException) {
            // Fallback (sehr altes SQLite): WAL einspielen, Datei kopieren
            @unlink($target);
            $this->db->pdo()->exec('PRAGMA wal_checkpoint(TRUNCATE)');
            if (!@copy($this->db->file(), $target)) {
                throw new \RuntimeException('Sicherung fehlgeschlagen');
            }
        }
    }

    /** Anmeldungen, Tickets und Drosselung gehoeren nicht in die Sicherung. */
    protected function stripVolatile(string $file): void
    {
        $pdo = new \PDO('sqlite:' . $file, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA journal_mode = DELETE');
        foreach (self::VOLATILE_TABLES as $t) {
            if ($pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = '$t'")->fetchColumn()) {
                $pdo->exec("DELETE FROM $t");
            }
        }
        $pdo = null;
        @unlink($file . '-wal');
        @unlink($file . '-shm');
    }

    protected function schemaVersion(string $file): int
    {
        $pdo = new \PDO('sqlite:' . $file, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        if (!$pdo->query("SELECT 1 FROM sqlite_master WHERE name = 'schema_migrations'")->fetchColumn()) {
            return 0;
        }
        return (int) $pdo->query('SELECT MAX(CAST(version AS INTEGER)) FROM schema_migrations')->fetchColumn();
    }

    protected function countEntries(string $file): int
    {
        $pdo = new \PDO('sqlite:' . $file, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        if (!$pdo->query("SELECT 1 FROM sqlite_master WHERE name = 'entries'")->fetchColumn()) {
            return 0;
        }
        return (int) $pdo->query('SELECT COUNT(*) FROM entries WHERE deleted_at IS NULL')->fetchColumn();
    }
}
