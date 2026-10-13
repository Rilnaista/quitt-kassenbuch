<?php

declare(strict_types=1);

namespace Quitt\App;

/**
 * Konfiguration.
 * - Datenordner: ENV QUITT_DATA_DIR > <app>/config.local.php ['data_dir'] > Vorgabe des Einstiegs
 *   (Release-Paket: <installationsordner>/data neben app/, siehe release-layout.md) > <app>/data
 * - Update-Quelle: 'update_repo' (GitHub "owner/name"), in data/config.json ueberschreibbar (Forks).
 * - Applikationswerte: Defaults, ueberschrieben durch <data>/config.json (pflegbar, z. B. KI-Modelle).
 */
final class Config
{
    public const DEFAULTS = [
        'log_level' => 'info',
        'log_max_files' => 6,
        'receipt_max_bytes' => 5 * 1024 * 1024,
        'token_lifetime_days' => 365,
        'download_ticket_ttl' => 120,
        'sync_max_entries' => 500,
        'client_log_per_minute' => 30,
        'backup_keep' => 10,
        'update_repo' => 'Rilnaista/quitt-kassenbuch',
        'update_check_ttl' => 6 * 3600,
        'orphan_receipt_grace_hours' => 24,
        'ai_default_models' => [
            'mistral' => 'mistral-small-latest',
            'openai' => 'gpt-4o-mini',
            'anthropic' => 'claude-haiku-4-5',
            'gemini' => 'gemini-2.5-flash',
            'ollama' => 'qwen2.5vl',
        ],
    ];

    /** @param array<string, mixed> $values */
    public function __construct(
        public readonly string $appDir,
        public readonly string $dataDir,
        private array $values = [],
    ) {
        $this->values = array_replace_recursive(self::DEFAULTS, $values);
    }

    /** @param string|null $defaultDataDir Datenordner, wenn weder ENV noch config.local.php einen setzen */
    public static function fromEnvironment(string $appDir, ?string $defaultDataDir = null): self
    {
        $dataDir = $defaultDataDir ?? $appDir . '/data';
        $local = $appDir . '/config.local.php';
        if (is_file($local)) {
            $cfg = require $local;
            if (is_array($cfg) && !empty($cfg['data_dir'])) {
                $dataDir = (string) $cfg['data_dir'];
            }
        }
        $env = getenv('QUITT_DATA_DIR');
        if (is_string($env) && $env !== '') {
            $dataDir = $env;
        }
        $dataDir = rtrim($dataDir, '/\\');

        $values = [];
        $json = $dataDir . '/config.json';
        if (is_file($json)) {
            $decoded = json_decode((string) file_get_contents($json), true);
            if (is_array($decoded)) {
                $values = $decoded;
            }
        }
        return new self($appDir, $dataDir, $values);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function version(): string
    {
        $file = $this->appDir . '/VERSION';
        return is_file($file) ? trim((string) file_get_contents($file)) : '0.0.0';
    }

    public function dbFile(): string
    {
        return $this->dataDir . '/quitt.sqlite';
    }

    public function path(string $sub): string
    {
        return $this->dataDir . '/' . ltrim($sub, '/');
    }
}
