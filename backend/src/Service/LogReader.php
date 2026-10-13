<?php

declare(strict_types=1);

namespace Quitt\Service;

use Quitt\Log\Logger;
use Quitt\Log\Masker;

/** Liest die JSON-Lines-Protokolle (Monatsdateien), neueste zuerst. Gibt nur maskierte Daten heraus. */
class LogReader
{
    public const LEVELS = ['error', 'warning', 'info'];
    public const DEFAULT_LIMIT = 200;
    public const MAX_LIMIT = 1000;

    public function __construct(private readonly Logger $logger)
    {
    }

    /**
     * @param string|null $level Mindeststufe: error (error+), warning (warning+), info (alles ab info)
     * @return list<array{ts: string, level: string, channel: string, message: string, context: array|\stdClass}>
     */
    public function read(?string $level = null, int $limit = self::DEFAULT_LIMIT): array
    {
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $min = $level === null ? 0 : (Logger::LEVELS[$level] ?? 0);
        $out = [];
        foreach (array_reverse($this->logger->files()) as $file) {
            $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            for ($i = count($lines) - 1; $i >= 0; $i--) {
                $row = json_decode($lines[$i], true);
                if (!is_array($row) || !isset($row['ts'], $row['level'], $row['message'])) {
                    continue;
                }
                if ((Logger::LEVELS[$row['level']] ?? 0) < $min) {
                    continue;
                }
                $ctx = is_array($row['context'] ?? null) ? Masker::mask($row['context']) : [];
                $out[] = [
                    'ts' => (string) $row['ts'],
                    'level' => (string) $row['level'],
                    'channel' => (string) ($row['channel'] ?? ''),
                    'message' => Masker::maskText((string) $row['message']),
                    'context' => $ctx === [] ? new \stdClass() : $ctx,
                ];
                if (count($out) >= $limit) {
                    return $out;
                }
            }
        }
        return $out;
    }
}
