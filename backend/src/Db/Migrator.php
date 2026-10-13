<?php

declare(strict_types=1);

namespace Quitt\Db;

use Psr\Log\LoggerInterface;
use Quitt\Service\BackupService;

/**
 * Migrations-Runner. Dateien: migrations/NNNN_name.php, jede liefert
 * `return static function (PDO $pdo): void { ... };` (nur "up").
 * Vor dem Anwenden auf eine bestehende Datenbank wird automatisch gesichert.
 * Parallele Requests werden per Dateisperre serialisiert.
 */
final class Migrator
{
    public function __construct(
        private readonly Database $db,
        private readonly string $dir,
        private readonly BackupService $backup,
        private readonly LoggerInterface $log,
        private readonly string $lockFile,
    ) {
    }

    /** @return array<string, string> version => Datei (alle vorhandenen, sortiert) */
    public function available(): array
    {
        $out = [];
        foreach (glob($this->dir . '/*.php') ?: [] as $file) {
            if (preg_match('/^(\d{4})_[a-z0-9_]+\.php$/', basename($file), $m)) {
                $out[$m[1]] = $file;
            }
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    /** @return string[] bereits angewendete Versionen */
    public function applied(): array
    {
        if (!$this->db->tableExists('schema_migrations')) {
            return [];
        }
        return array_map('strval', array_column($this->db->all('SELECT version FROM schema_migrations ORDER BY version'), 'version'));
    }

    /** @return array<string, string> */
    public function pending(): array
    {
        return array_diff_key($this->available(), array_flip($this->applied()));
    }

    /** @return string[] in diesem Lauf angewendete Versionen */
    public function migrate(): array
    {
        if ($this->pending() === []) {
            return [];
        }
        $lock = fopen($this->lockFile, 'c');
        if ($lock === false) {
            throw new \RuntimeException('Migrationssperre nicht anlegbar');
        }
        try {
            flock($lock, LOCK_EX);
            $pending = $this->pending(); // nach Sperre erneut pruefen
            if ($pending === []) {
                return [];
            }
            if ($this->applied() !== []) {
                $path = $this->backup->autoBackup(BackupService::REASON_BEFORE_MIGRATION);
                $this->log->info('backup before migration', ['file' => basename($path)]);
            }
            $this->db->pdo()->exec(
                'CREATE TABLE IF NOT EXISTS schema_migrations (version TEXT PRIMARY KEY, name TEXT NOT NULL, applied_at TEXT NOT NULL)'
            );
            $done = [];
            foreach ($pending as $version => $file) {
                $fn = require $file;
                if (!is_callable($fn)) {
                    throw new \RuntimeException('Migration liefert keine Funktion: ' . basename($file));
                }
                $this->db->transaction(function (\PDO $pdo) use ($fn, $version, $file): void {
                    $fn($pdo);
                    $stmt = $pdo->prepare('INSERT INTO schema_migrations (version, name, applied_at) VALUES (?, ?, ?)');
                    $stmt->execute([$version, basename($file, '.php'), gmdate('Y-m-d\TH:i:s\Z')]);
                });
                $this->log->info('migration applied', ['version' => $version, 'name' => basename($file, '.php')]);
                $done[] = $version;
            }
            return $done;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
