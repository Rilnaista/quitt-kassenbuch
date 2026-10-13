<?php

declare(strict_types=1);

namespace Quitt\Service;

use Psr\Log\LoggerInterface;
use Quitt\App\Config;
use Quitt\App\DataDir;
use Quitt\Db\Database;
use Quitt\Db\Migrator;
use Quitt\Http\ApiException;

/**
 * Einspielen einer Sicherung (ZIP, quitt-backup/1).
 *
 * Ablauf: Validieren (Zip-Slip-Schutz per Namens-Whitelist, Groessenlimits, manifest, SQLite-Pruefung)
 * -> in Temp-Ordner im Datenordner entpacken -> aktuelle Anmeldungen (auth_tokens) in die neue DB uebernehmen
 * -> autoBackup(before_restore) -> atomar umschalten (rename DB + Belegordner) -> Migrationen.
 * Schlaegt irgendein Schritt nach dem Umschalten fehl, wird der alte Stand zurueckgesetzt.
 *
 * Entscheidung Tokens: Die Sicherung enthaelt keine Anmeldungen; die der laufenden Installation bleiben
 * erhalten, damit sich kein Geraet aussperrt. PIN/Recovery-Hash und Einstellungen kommen aus der Sicherung.
 */
class RestoreService
{
    private const FIELD = 'file';
    private const SINGLE_FILES = ['manifest.json', 'database.sqlite', 'app-settings.json'];
    private const REQUIRED_TABLES = ['meta', 'entries', 'receipts', 'settings', 'schema_migrations', 'sequences'];

    public function __construct(
        private readonly Config $config,
        private readonly Database $db,
        private readonly Migrator $migrator,
        private readonly BackupService $backup,
        private readonly LoggerInterface $log,
    ) {
    }

    public function maxUploadBytes(): int
    {
        return (int) $this->config->get('restore_max_bytes', 256 * 1024 * 1024);
    }

    /** @return array{ok: bool, entries: int, receipts: int} */
    public function restoreUpload(string $bytes): array
    {
        if ($bytes === '' || strlen($bytes) > $this->maxUploadBytes()) {
            throw ApiException::validation([self::FIELD => $bytes === '' ? 'validation.required' : 'validation.backup_too_large']);
        }
        $tmp = $this->backup->tempPath('upload');
        try {
            if (file_put_contents($tmp, $bytes) === false) {
                throw new \RuntimeException('Upload nicht zwischenspeicherbar');
            }
            return $this->restoreFile($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    /** @return array{ok: bool, entries: int, receipts: int} */
    public function restoreAutomatic(string $name): array
    {
        $path = $this->backup->pathFor($name);
        if ($path === null) {
            throw ApiException::notFound();
        }
        return $this->restoreFile($path);
    }

    /** @return array{ok: bool, entries: int, receipts: int} */
    public function restoreFile(string $zipPath): array
    {
        $lock = fopen($this->config->path('.restore.lock'), 'c');
        if ($lock === false) {
            throw new \RuntimeException('Sperrdatei nicht anlegbar');
        }
        flock($lock, LOCK_EX);
        $this->backup->cleanupTemp();
        $stage = $this->backup->tempPath('restore');
        try {
            if (!@mkdir($stage, 0775, true)) {
                throw new \RuntimeException('Temp-Ordner nicht anlegbar');
            }
            $receiptCount = $this->extract($zipPath, $stage);
            $entries = $this->validateDatabase($stage . '/database.sqlite');
            $this->carryOverTokens($stage . '/database.sqlite');

            $this->backup->autoBackup(BackupService::REASON_BEFORE_RESTORE);
            $this->swap($stage);
            $this->log->info('restore completed', ['entries' => $entries, 'receipts' => $receiptCount]);
            return ['ok' => true, 'entries' => $entries, 'receipts' => $receiptCount];
        } catch (ApiException $e) {
            $this->log->warning('restore rejected', ['code' => $e->errorCode]);
            throw $e;
        } catch (\Throwable $e) {
            $this->log->error('restore failed', ['exception' => $e]);
            throw $e;
        } finally {
            BackupService::rmrf($stage);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Whitelist der erlaubten Eintragsnamen; alles andere (auch "../", absolute Pfade) wird abgelehnt. */
    public static function isAllowedName(string $name): bool
    {
        if (in_array($name, self::SINGLE_FILES, true)) {
            return true;
        }
        return preg_match('#^receipts/[0-9a-f-]{36}$#', $name) === 1;
    }

    /** @return int Anzahl entpackter Belegdateien; legt $stage/{manifest.json,database.sqlite,receipts/} an */
    private function extract(string $zipPath, string $stage): int
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::RDONLY | \ZipArchive::CHECKCONS) !== true) {
            throw $this->invalid();
        }
        try {
            $maxFiles = (int) $this->config->get('restore_max_files', 100000);
            $maxUnpacked = (int) $this->config->get('restore_max_unpacked_bytes', 1024 * 1024 * 1024);
            if ($zip->numFiles < 2 || $zip->numFiles > $maxFiles) {
                throw $this->invalid();
            }
            $plan = [];
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $st = $zip->statIndex($i);
                $name = is_array($st) ? (string) $st['name'] : '';
                if (!self::isAllowedName($name) || isset($plan[$name])) {
                    throw $this->invalid();
                }
                $total += (int) $st['size'];
                if ($total > $maxUnpacked || ($name === 'manifest.json' && $st['size'] > 65536)) {
                    throw ApiException::validation([self::FIELD => 'validation.backup_too_large']);
                }
                $plan[$name] = (int) $st['size'];
            }
            if (!isset($plan['manifest.json'], $plan['database.sqlite'])) {
                throw $this->invalid();
            }

            $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
            if (!is_array($manifest) || ($manifest['format'] ?? null) !== BackupService::FORMAT || !is_int($manifest['schema_version'] ?? null)) {
                throw $this->invalid();
            }
            if ($manifest['schema_version'] > $this->latestSchema()) {
                throw new ApiException(422, 'backup_too_new');
            }

            @mkdir($stage . '/receipts', 0775, true);
            $receipts = 0;
            foreach ($plan as $name => $size) {
                if ($name === 'manifest.json' || $name === 'app-settings.json') {
                    continue;
                }
                $this->copyOut($zip, $name, $stage . '/' . $name, $size);
                if (str_starts_with($name, 'receipts/')) {
                    $receipts++;
                }
            }
            return $receipts;
        } finally {
            $zip->close();
        }
    }

    private function copyOut(\ZipArchive $zip, string $name, string $dest, int $expected): void
    {
        $in = $zip->getStream($name);
        $out = fopen($dest, 'wb');
        if ($in === false || $out === false) {
            throw $this->invalid();
        }
        $written = 0;
        while (!feof($in)) {
            $chunk = fread($in, 65536);
            if ($chunk === false) {
                break;
            }
            $written += strlen($chunk);
            if ($written > $expected) { // Zip-Bombe / luegende Groessenangabe
                fclose($in);
                fclose($out);
                throw $this->invalid();
            }
            fwrite($out, $chunk);
        }
        fclose($in);
        fclose($out);
        if ($written !== $expected) {
            throw $this->invalid();
        }
    }

    private function latestSchema(): int
    {
        $versions = array_map('intval', array_keys($this->migrator->available()));
        return $versions === [] ? 0 : max($versions);
    }

    /** @return int Anzahl Buchungen (ohne Papierkorb) */
    private function validateDatabase(string $file): int
    {
        try {
            $pdo = new \PDO('sqlite:' . $file, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            if (strtolower((string) $pdo->query('PRAGMA quick_check')->fetchColumn()) !== 'ok') {
                throw $this->invalid();
            }
            foreach (self::REQUIRED_TABLES as $t) {
                if (!$pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = '$t'")->fetchColumn()) {
                    throw $this->invalid();
                }
            }
            $schema = (int) $pdo->query('SELECT MAX(CAST(version AS INTEGER)) FROM schema_migrations')->fetchColumn();
            if ($schema > $this->latestSchema()) {
                throw new ApiException(422, 'backup_too_new');
            }
            if ($schema < 1) {
                throw $this->invalid();
            }
            return (int) $pdo->query('SELECT COUNT(*) FROM entries WHERE deleted_at IS NULL')->fetchColumn();
        } catch (\PDOException) {
            throw $this->invalid();
        }
    }

    /** Anmeldungen der laufenden Installation in die einzuspielende Datenbank uebernehmen. */
    private function carryOverTokens(string $file): void
    {
        if (!$this->db->tableExists('auth_tokens')) {
            return;
        }
        $rows = $this->db->all('SELECT token_hash, created_at, last_used_at, expires_at FROM auth_tokens');
        $pdo = new \PDO('sqlite:' . $file, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA journal_mode = DELETE');
        if (!$pdo->query("SELECT 1 FROM sqlite_master WHERE name = 'auth_tokens'")->fetchColumn()) {
            return;
        }
        $pdo->exec('DELETE FROM auth_tokens');
        $stmt = $pdo->prepare('INSERT OR REPLACE INTO auth_tokens (token_hash, created_at, last_used_at, expires_at) VALUES (?, ?, ?, ?)');
        foreach ($rows as $r) {
            $stmt->execute([$r['token_hash'], $r['created_at'], $r['last_used_at'], $r['expires_at']]);
        }
    }

    /** Atomarer Austausch (rename) von DB und Belegordner; bei Fehler/Migrationsfehler Rueckweg. */
    private function swap(string $stage): void
    {
        $dbFile = $this->config->dbFile();
        $recDir = $this->config->path('receipts');
        $oldDb = $this->backup->tempPath('old-db');
        $oldRec = $this->backup->tempPath('old-receipts');
        $dbMoved = $recMoved = false;

        $this->db->pdo()->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        $this->db->close();
        try {
            if (!@rename($dbFile, $oldDb)) {
                throw new \RuntimeException('Datenbank nicht verschiebbar');
            }
            $dbMoved = true;
            @unlink($dbFile . '-wal');
            @unlink($dbFile . '-shm');
            if (is_dir($recDir)) {
                if (!@rename($recDir, $oldRec)) {
                    throw new \RuntimeException('Belegordner nicht verschiebbar');
                }
                $recMoved = true;
            }
            if (!@rename($stage . '/receipts', $recDir) || !@rename($stage . '/database.sqlite', $dbFile)) {
                throw new \RuntimeException('Neuer Stand nicht aktivierbar');
            }
            DataDir::ensure($this->config->dataDir);
            $this->migrator->migrate();
        } catch (\Throwable $e) {
            $this->db->close();
            if ($dbMoved) {
                @unlink($dbFile);
                @unlink($dbFile . '-wal');
                @unlink($dbFile . '-shm');
                @rename($oldDb, $dbFile);
            }
            if ($recMoved) {
                BackupService::rmrf($recDir);
                @rename($oldRec, $recDir);
            }
            throw $e;
        }
        @unlink($oldDb);
        BackupService::rmrf($oldRec);
    }

    private function invalid(): ApiException
    {
        return ApiException::validation([self::FIELD => 'validation.backup_invalid']);
    }
}
