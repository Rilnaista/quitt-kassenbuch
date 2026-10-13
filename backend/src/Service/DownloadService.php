<?php

declare(strict_types=1);

namespace Quitt\Service;

use Quitt\App\Config;
use Quitt\Db\Database;
use Quitt\Http\Access;
use Quitt\Http\ApiException;
use Quitt\Support\Clock;

/**
 * Einmal-Tickets fuer Downloads (?ticket=...), 120 s gueltig, nur als SHA-256 gespeichert.
 *
 * ERWEITERUNGSPUNKT: Download-Ziele werden je Domaene registriert:
 *   $downloads->registerTarget('statement', Access::READ, fn (array $params): string => 'statement.pdf?from=..&to=..');
 * Der Resolver validiert params (ApiException 422) und liefert den Pfad RELATIV zu <base>/api/v1
 * (optional mit Query). Die Ziel-Route muss mit Registrar::download() registriert sein.
 */
final class DownloadService
{
    /** @var array<string, array{access: string, resolver: callable}> */
    private array $targets = [];

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly Config $config,
    ) {
    }

    /** @param string $access Access::READ (auch bei Lesen ohne PIN) oder Access::PRIVATE (nur mit Token) */
    public function registerTarget(string $name, string $access, callable $resolver): void
    {
        if (!in_array($access, [Access::READ, Access::PRIVATE], true)) {
            throw new \InvalidArgumentException('Download-Ziel braucht Access::READ oder Access::PRIVATE');
        }
        $this->targets[$name] = ['access' => $access, 'resolver' => $resolver];
    }

    public function hasTarget(string $name): bool
    {
        return isset($this->targets[$name]);
    }

    /** @return string[] */
    public function targetNames(): array
    {
        return array_keys($this->targets);
    }

    public function targetAccess(string $name): ?string
    {
        return $this->targets[$name]['access'] ?? null;
    }

    /**
     * @param array<string, mixed> $params
     * @return string URL relativ zu <base>, z. B. "api/v1/receipts/<id>/file?ticket=..."
     */
    public function create(string $target, array $params): string
    {
        if (!isset($this->targets[$target])) {
            throw ApiException::validation(['target' => 'validation.invalid_choice']);
        }
        $relative = ($this->targets[$target]['resolver'])($params);
        [$path, $query] = array_pad(explode('?', ltrim((string) $relative, '/'), 2), 2, '');
        $query = self::canonicalQuery($query);

        $ticket = bin2hex(random_bytes(24));
        $now = $this->clock->now()->getTimestamp();
        $ttl = max(10, (int) $this->config->get('download_ticket_ttl', 120));
        $this->db->transaction(function () use ($ticket, $target, $path, $query, $now, $ttl): void {
            $this->db->run('DELETE FROM download_tickets WHERE expires_at < ?', [$now - 3600]);
            $this->db->run(
                'INSERT INTO download_tickets (ticket_hash, target, path, query, expires_at, used_at) VALUES (?, ?, ?, ?, ?, NULL)',
                [hash('sha256', $ticket), $target, $path, $query, $now + $ttl]
            );
        });
        return 'api/v1/' . $path . '?' . ($query !== '' ? $query . '&' : '') . 'ticket=' . $ticket;
    }

    /**
     * Prueft und verbraucht ein Ticket fuer genau diesen Pfad (+ Query ohne ticket).
     * @param string $path Pfad relativ zu <base>/api/v1 (ohne fuehrenden Slash)
     * @param array<string, mixed> $query
     */
    public function consume(string $ticket, string $path, array $query): bool
    {
        if ($ticket === '' || strlen($ticket) > 200) {
            return false;
        }
        unset($query['ticket']);
        $now = $this->clock->now()->getTimestamp();
        return $this->db->transaction(function () use ($ticket, $path, $query, $now): bool {
            $hash = hash('sha256', $ticket);
            $row = $this->db->one('SELECT path, query, expires_at, used_at FROM download_tickets WHERE ticket_hash = ?', [$hash]);
            if ($row === null || $row['used_at'] !== null || (int) $row['expires_at'] < $now) {
                return false;
            }
            if ($row['path'] !== ltrim($path, '/') || $row['query'] !== self::canonicalQuery(http_build_query($query))) {
                return false;
            }
            $this->db->run('UPDATE download_tickets SET used_at = ? WHERE ticket_hash = ?', [$now, $hash]);
            return true;
        });
    }

    public static function canonicalQuery(string $query): string
    {
        if ($query === '') {
            return '';
        }
        parse_str($query, $params);
        unset($params['ticket']);
        ksort($params);
        return http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }
}
