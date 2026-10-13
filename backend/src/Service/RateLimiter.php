<?php

declare(strict_types=1);

namespace Quitt\Service;

use Quitt\Db\Database;
use Quitt\Http\ApiException;
use Quitt\Support\Clock;

/**
 * Sperre gegen Durchprobieren (pro Installation und scope).
 * Ab $freeAttempts Fehlversuchen: 30 s, 60 s, 120 s ... max 1 h.
 * Liegt der letzte Fehlversuch laenger als $decaySeconds zurueck, beginnt die Zaehlung neu.
 */
final class RateLimiter
{
    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly string $scope,
        private readonly int $freeAttempts = 5,
        private readonly int $baseSeconds = 30,
        private readonly int $maxSeconds = 3600,
        private readonly int $decaySeconds = 86400,
    ) {
    }

    public function retryAfter(): int
    {
        $row = $this->row();
        return $row === null ? 0 : max(0, (int) $row['locked_until'] - $this->now());
    }

    public function assertNotLocked(): void
    {
        $wait = $this->retryAfter();
        if ($wait > 0) {
            throw ApiException::rateLimited($wait);
        }
    }

    /** Fehlversuch verbuchen. @return int Sperrdauer in Sekunden (0 = keine) */
    public function fail(): int
    {
        return $this->db->transaction(function (): int {
            $now = $this->now();
            $row = $this->row();
            $failures = 1;
            if ($row !== null && $now - (int) $row['last_failure'] < $this->decaySeconds) {
                $failures = (int) $row['failures'] + 1;
            }
            $lock = 0;
            if ($failures >= $this->freeAttempts) {
                $exp = min($failures - $this->freeAttempts, 20);
                $lock = (int) min($this->maxSeconds, $this->baseSeconds * (2 ** $exp));
            }
            $this->db->run(
                'INSERT INTO login_attempts (scope, failures, locked_until, last_failure) VALUES (?, ?, ?, ?)
                 ON CONFLICT(scope) DO UPDATE SET failures = excluded.failures, locked_until = excluded.locked_until, last_failure = excluded.last_failure',
                [$this->scope, $failures, $now + $lock, $now]
            );
            return $lock;
        });
    }

    public function reset(): void
    {
        $this->db->run('DELETE FROM login_attempts WHERE scope = ?', [$this->scope]);
    }

    public function failures(): int
    {
        return (int) ($this->row()['failures'] ?? 0);
    }

    private function row(): ?array
    {
        return $this->db->one('SELECT failures, locked_until, last_failure FROM login_attempts WHERE scope = ?', [$this->scope]);
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }
}
