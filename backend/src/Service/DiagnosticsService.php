<?php

declare(strict_types=1);

namespace Quitt\Service;

use Quitt\App\Config;
use Quitt\Db\Database;
use Quitt\Db\Meta;
use Quitt\Db\Migrator;
use Quitt\I18n\Lang;
use Quitt\Log\Masker;

/**
 * Diagnosepaket (quitt-diagnostics/1). Enthaelt NIE PIN-Hash, Tokens, Recovery-Code/-Hash, ai_key:
 * Settings kommen aus publicView() (ohne ai_key), Logs sind maskiert, und zusaetzlich werden bekannte
 * geheime Werte (Hashes, Schluessel) im fertigen JSON hart ersetzt.
 */
class DiagnosticsService
{
    public const FORMAT = 'quitt-diagnostics/1';
    public const MIN_FREE_BYTES = 50 * 1024 * 1024;
    private const EXTENSIONS = ['pdo_sqlite', 'json', 'mbstring', 'zip', 'curl'];

    public function __construct(
        private readonly Config $config,
        private readonly Database $db,
        private readonly Meta $meta,
        private readonly Migrator $migrator,
        private readonly SettingsService $settings,
        private readonly LogReader $logs,
        private readonly Lang $lang,
        private readonly BackupService $backup,
    ) {
    }

    /** @return string JSON-Text */
    public function buildJson(bool $https, bool $docker, string $basePath): string
    {
        $data = $this->build($https, $docker, $basePath);
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        foreach ($this->secretValues() as $secret) {
            $json = str_replace(substr(json_encode($secret, JSON_UNESCAPED_SLASHES), 1, -1), Masker::MASK, $json);
        }
        return $json;
    }

    /** @return array<string, mixed> */
    public function build(bool $https, bool $docker, string $basePath): array
    {
        $free = @disk_free_space($this->config->dataDir);
        $free = $free === false ? 0 : (int) $free;
        $dbSize = is_file($this->db->file()) ? (int) filesize($this->db->file()) : 0;
        $meta = [
            'version' => $this->config->version(),
            'php' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'os' => PHP_OS_FAMILY . ' ' . php_uname('r'),
            'modules' => array_values(array_filter(self::EXTENSIONS, 'extension_loaded')),
            'base_path' => $basePath,
            'https' => $https,
            'docker' => $docker,
            'disk_free' => $free,
            'db_size' => $dbSize,
            'counts' => $this->counts(),
            'settings' => $this->settings->publicView(),
        ];
        return Masker::mask([
            'format' => self::FORMAT,
            'meta' => $meta,
            'checks' => $this->checks($https, $free),
            'logs' => $this->logs->read(null, 200),
        ]);
    }

    /** @return list<array{name: string, ok: bool, detail: string}> */
    private function checks(bool $https, int $free): array
    {
        $t = fn (string $k, array $p = []) => $this->lang->t('diag.' . $k, $p);
        $checks = [];
        $checks[] = ['name' => $t('php_version'), 'ok' => version_compare(PHP_VERSION, '8.1.0', '>='), 'detail' => PHP_VERSION];
        foreach (self::EXTENSIONS as $ext) {
            $loaded = extension_loaded($ext);
            $optional = $ext === 'curl';
            $checks[] = [
                'name' => $t('extension', ['name' => $ext]),
                'ok' => $loaded || $optional,
                'detail' => $loaded ? $t('available') : ($optional ? $t('optional_missing') : $t('missing')),
            ];
        }
        $writable = is_dir($this->config->dataDir) && is_writable($this->config->dataDir);
        $checks[] = ['name' => $t('data_writable'), 'ok' => $writable, 'detail' => $writable ? $t('yes') : $t('no')];
        $checks[] = ['name' => $t('https'), 'ok' => $https, 'detail' => $https ? $t('yes') : $t('no_https')];
        $checks[] = [
            'name' => $t('free_space'),
            'ok' => $free >= self::MIN_FREE_BYTES,
            'detail' => $t('megabytes', ['mb' => (int) round($free / 1048576)]),
        ];
        $integrity = $this->integrity();
        $checks[] = ['name' => $t('database'), 'ok' => $integrity === 'ok', 'detail' => $integrity === 'ok' ? $t('ok') : $t('database_error')];
        $pending = count($this->migrator->pending());
        $checks[] = ['name' => $t('schema'), 'ok' => $pending === 0, 'detail' => $pending === 0 ? $t('up_to_date') : $t('pending', ['n' => $pending])];
        return $checks;
    }

    private function integrity(): string
    {
        try {
            return strtolower((string) $this->db->scalar('PRAGMA quick_check'));
        } catch (\Throwable) {
            return 'error';
        }
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        $c = ['entries' => 0, 'trash' => 0, 'receipts' => 0, 'backups' => count($this->backup->list()), 'log_files' => 0];
        try {
            if ($this->db->tableExists('entries')) {
                $c['entries'] = (int) $this->db->scalar('SELECT COUNT(*) FROM entries WHERE deleted_at IS NULL');
                $c['trash'] = (int) $this->db->scalar('SELECT COUNT(*) FROM entries WHERE deleted_at IS NOT NULL');
                $c['receipts'] = (int) $this->db->scalar('SELECT COUNT(*) FROM receipts WHERE deleted_at IS NULL');
            }
        } catch (\Throwable) {
        }
        $c['log_files'] = count(glob($this->config->path('logs') . '/quitt-*.log') ?: []);
        return $c;
    }

    /** @return list<string> bekannte geheime Werte (Hashes, Schluessel), die nie im Paket stehen duerfen */
    private function secretValues(): array
    {
        $out = [];
        try {
            foreach ([AuthService::META_PIN, AuthService::META_RECOVERY] as $k) {
                $out[] = (string) $this->meta->get($k);
            }
            $out[] = (string) ($this->settings->all()['ai_key'] ?? '');
            if ($this->db->tableExists('auth_tokens')) {
                foreach ($this->db->all('SELECT token_hash FROM auth_tokens') as $r) {
                    $out[] = (string) $r['token_hash'];
                }
            }
        } catch (\Throwable) {
        }
        return array_values(array_filter(array_unique($out), fn ($s) => strlen($s) >= 6));
    }
}
