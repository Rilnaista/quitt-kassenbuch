<?php

declare(strict_types=1);

namespace Quitt\Db;

use PDO;

/**
 * SQLite-Verbindung (lazy). WAL, foreign_keys ON, busy_timeout.
 * Schreibende Transaktionen laufen als BEGIN IMMEDIATE, damit Zaehler (number/version)
 * in Commit-Reihenfolge vergeben werden; verschachtelte Aufrufe werden zusammengefasst.
 */
final class Database
{
    private ?PDO $pdo = null;
    private int $depth = 0;

    public function __construct(private readonly string $file)
    {
    }

    public function file(): string
    {
        return $this->file;
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $pdo = new PDO('sqlite:' . $this->file, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ]);
            $pdo->exec('PRAGMA busy_timeout = 5000');
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA synchronous = NORMAL');
            $this->pdo = $pdo;
        }
        return $this->pdo;
    }

    /**
     * @template T
     * @param callable(PDO): T $fn
     * @return T
     */
    public function transaction(callable $fn): mixed
    {
        $pdo = $this->pdo();
        if ($this->depth > 0) {
            $this->depth++;
            try {
                return $fn($pdo);
            } finally {
                $this->depth--;
            }
        }
        $pdo->exec('BEGIN IMMEDIATE');
        $this->depth = 1;
        try {
            $result = $fn($pdo);
            $pdo->exec('COMMIT');
            return $result;
        } catch (\Throwable $e) {
            $pdo->exec('ROLLBACK');
            throw $e;
        } finally {
            $this->depth = 0;
        }
    }

    /** @param array<int|string, mixed> $params */
    public function run(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /** @param array<int|string, mixed> $params */
    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** @param array<int|string, mixed> $params */
    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    /** @param array<int|string, mixed> $params */
    public function scalar(string $sql, array $params = []): mixed
    {
        $v = $this->run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    public function tableExists(string $name): bool
    {
        return $this->scalar("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?", [$name]) !== null;
    }

    public function close(): void
    {
        $this->pdo = null;
    }
}
