<?php

declare(strict_types=1);

namespace Quitt\Service;

use Psr\Log\LoggerInterface;
use Quitt\App\Config;
use Quitt\Db\Database;
use Quitt\Db\Migrator;
use Quitt\Http\ApiException;
use Quitt\Support\Clock;
use Quitt\Update\HttpClient;

/**
 * In-App-Update (REQ-061). Quelle: GitHub Releases von Config 'update_repo' (inkl. Pre-Releases).
 *
 * check(): neueste Fassung ermitteln, Ergebnis in <data>/update-check.json gecacht (Erfolg 6 h, Fehler 30 min).
 *   Fehler bleiben still (available=false) und landen nur im Log.
 * apply(): Sicherung (before_update) -> Paket + .sha256 laden -> SHA-256 pruefen -> in Temp-Ordner entpacken
 *   (Namenspruefung) -> Eintraege der obersten Ebene per rename() austauschen (alte in Temp-Ordner verschieben)
 *   -> DB-Kopie -> Migrationen der NEUEN Dateien -> fertig. Jeder Fehler nach dem Austausch: alte Dateien und
 *   DB-Kopie zurueck. Datenordner (data/), setup.php und app/config.local.php bleiben unberuehrt.
 *
 * Entscheidungen:
 * - Nur im Release-Layout (<root>/version.json, <root>/app == appDir, <root>/api/index.php) moeglich, damit eine
 *   Entwicklungsumgebung nie ueberschrieben wird.
 * - Temp-Ordner liegt im Installationsordner (gleiches Dateisystem -> rename atomar je Eintrag), Name beginnt
 *   mit ".quitt-update-" (Root-.htaccess sperrt Punkt-Dateien, zusaetzlich eigene .htaccess).
 * - Migrationen laufen im selben Prozess: Der (alte) Migrator fuehrt die NEUEN Migrationsdateien aus
 *   (Migrationen sind selbststaendige PDO-Closures). Bei Fehler wird die DB aus einer Rohkopie (inkl. Anmeldungen)
 *   zurueckgeschrieben, damit niemand abgemeldet wird.
 */
class UpdateService
{
    public const CACHE_FILE = 'update-check.json';
    public const ERROR_TTL = 1800;
    public const STAGE_PREFIX = '.quitt-update-';
    /** Eintraege der obersten Ebene, die ein Update nie anfasst */
    public const PROTECTED = ['data', 'setup.php'];
    /** Dateien in app/, die ins neue app/ uebernommen werden */
    public const CARRY_OVER = ['config.local.php'];
    private const VERSION_PATTERN = '/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.]+)?$/';
    private const MAX_FILES = 20000;
    private const MAX_UNPACKED = 300 * 1024 * 1024;

    private readonly string $root;
    /** true, wenn ein Rollback unvollstaendig war: Temp-Ordner (mit alten Dateien) NICHT loeschen */
    private bool $keepStage = false;

    public function __construct(
        private readonly Config $config,
        private readonly BackupService $backup,
        private readonly Migrator $migrator,
        private readonly Database $db,
        private readonly HttpClient $http,
        private readonly Clock $clock,
        private readonly LoggerInterface $log,
        private readonly bool $docker,
        ?string $installRoot = null,
    ) {
        $this->root = rtrim($installRoot ?? dirname($config->appDir), '/\\');
    }

    /** @return array{current: string, latest: ?string, available: bool, notes: ?string, published_at: ?string, docker: bool} */
    public function check(bool $force = false): array
    {
        $current = $this->config->version();
        $cached = $force ? null : $this->readCache();
        if ($cached === null) {
            try {
                $release = $this->latestRelease();
                $this->writeCache($release, false);
            } catch (\Throwable $e) {
                $this->log->warning('update check failed: {why}', ['why' => $e->getMessage()]);
                $this->writeCache(null, true);
                $release = null;
            }
        } else {
            $release = $cached['release'];
        }
        return [
            'current' => $current,
            'latest' => $release['version'] ?? null,
            'available' => $release !== null && version_compare($release['version'], $current, '>'),
            'notes' => $release['notes'] ?? null,
            'published_at' => $release['published_at'] ?? null,
            'docker' => $this->docker,
        ];
    }

    /**
     * Neueste veroeffentlichte Fassung (inkl. Pre-Release) mit Paket + Pruefsumme.
     * @return array{version: string, notes: ?string, published_at: ?string, zip_url: string, sha_url: string}|null
     * @throws \RuntimeException bei Netz-/Formatfehlern
     */
    public function latestRelease(): ?array
    {
        $repo = (string) $this->config->get('update_repo', '');
        if (!preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo)) {
            throw new \RuntimeException('update_repo ungueltig');
        }
        $res = $this->http->get('https://api.github.com/repos/' . $repo . '/releases?per_page=30', $this->headers(), 20);
        if ($res['status'] !== 200) {
            throw new \RuntimeException('GitHub-Antwort ' . $res['status']);
        }
        $list = json_decode($res['body'], true);
        if (!is_array($list)) {
            throw new \RuntimeException('GitHub-Antwort ist kein JSON');
        }
        return self::pickRelease($list);
    }

    /**
     * Waehlt aus einer GitHub-Releaseliste die hoechste Fassung mit passenden Assets.
     * @param array<mixed> $list
     * @return array{version: string, notes: ?string, published_at: ?string, zip_url: string, sha_url: string}|null
     */
    public static function pickRelease(array $list): ?array
    {
        $best = null;
        foreach ($list as $rel) {
            if (!is_array($rel) || !empty($rel['draft'])) {
                continue;
            }
            $version = ltrim((string) ($rel['tag_name'] ?? ''), 'vV');
            if (!preg_match(self::VERSION_PATTERN, $version)) {
                continue;
            }
            $zip = $sha = null;
            foreach ((array) ($rel['assets'] ?? []) as $a) {
                $name = (string) ($a['name'] ?? '');
                $url = (string) ($a['browser_download_url'] ?? '');
                if (!str_starts_with($url, 'https://')) {
                    continue;
                }
                if ($name === 'quitt-' . $version . '.zip') {
                    $zip = $url;
                } elseif ($name === 'quitt-' . $version . '.zip.sha256') {
                    $sha = $url;
                }
            }
            if ($zip === null || $sha === null) {
                continue;
            }
            if ($best === null || version_compare($version, $best['version'], '>')) {
                $best = [
                    'version' => $version,
                    'notes' => isset($rel['body']) && is_string($rel['body']) ? $rel['body'] : null,
                    'published_at' => isset($rel['published_at']) && is_string($rel['published_at']) ? $rel['published_at'] : null,
                    'zip_url' => $zip,
                    'sha_url' => $sha,
                ];
            }
        }
        return $best;
    }

    /** Liegt die App im Release-Layout (nur dann ist ein Update moeglich)? */
    public function isReleaseLayout(): bool
    {
        $app = realpath($this->config->appDir);
        return $app !== false
            && realpath($this->root . '/app') === $app
            && is_file($this->root . '/version.json')
            && is_file($this->root . '/api/index.php');
    }

    /** @return array{ok: bool, from: string, to: string} */
    public function apply(): array
    {
        if ($this->docker) {
            throw new ApiException(409, 'docker_managed');
        }
        if (!$this->isReleaseLayout()) {
            throw $this->fail('update.err_not_release');
        }
        @set_time_limit(900);
        ignore_user_abort(true);
        $lock = fopen($this->config->path('.update.lock'), 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw $this->fail('update.err_running');
        }
        $from = $this->config->version();
        $stage = null;
        $this->keepStage = false;
        try {
            try {
                $release = $this->latestRelease();
            } catch (\Throwable $e) {
                $this->log->warning('update: check failed: {why}', ['why' => $e->getMessage()]);
                throw $this->fail('update.err_check');
            }
            if ($release === null || !version_compare($release['version'], $from, '>')) {
                throw $this->fail('update.err_no_update');
            }
            $to = $release['version'];
            $this->log->info('update started', ['from' => $from, 'to' => $to]);

            $this->backup->cleanupTemp();
            $this->cleanupStages();
            try {
                $backupFile = $this->backup->autoBackup(BackupService::REASON_BEFORE_UPDATE);
            } catch (\Throwable $e) {
                $this->log->error('update: backup failed', ['exception' => $e]);
                throw $this->fail('update.err_backup');
            }
            $this->log->info('update: backup created', ['file' => basename($backupFile)]);

            $stage = $this->root . '/' . self::STAGE_PREFIX . bin2hex(random_bytes(6));
            $this->prepareStage($stage);
            $zip = $stage . '/package.zip';
            $this->download($release, $zip);
            $this->log->info('update: package verified', ['to' => $to, 'size' => (int) filesize($zip)]);

            $this->extract($zip, $stage . '/new', $to);
            @unlink($zip);
            $this->carryOver($stage . '/new');

            $moved = $this->swap($stage . '/new', $stage . '/old');
            $this->log->info('update: files replaced', ['entries' => count($moved)]);

            $dbCopy = $this->backup->tempPath('update-db');
            try {
                $this->copyDatabase($dbCopy);
                $applied = $this->migrator->migrate();
                $this->log->info('update: migrations done', ['applied' => $applied]);
                $this->verify($to);
            } catch (\Throwable $e) {
                $this->log->error('update: failed after replacing files, rolling back', ['exception' => $e]);
                $this->restoreDatabase($dbCopy);
                $this->rollback($moved, $stage . '/old');
                throw $this->fail($e instanceof ApiException ? 'update.err_verify' : 'update.err_migration');
            } finally {
                @unlink($dbCopy);
            }

            @unlink($this->config->path(self::CACHE_FILE));
            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }
            $this->log->info('update completed', ['from' => $from, 'to' => $to]);
            return ['ok' => true, 'from' => $from, 'to' => $to];
        } finally {
            if ($stage !== null && !$this->keepStage) {
                BackupService::rmrf($stage);
            } elseif ($stage !== null) {
                $this->log->critical('update: old files kept for manual recovery', ['dir' => basename($stage)]);
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** rename() als Erweiterungspunkt (Tests simulieren damit einen Fehler mitten im Austausch). */
    protected function move(string $from, string $to): bool
    {
        return @rename($from, $to);
    }

    /** @param array{zip_url: string, sha_url: string, version: string} $release */
    private function download(array $release, string $zip): void
    {
        try {
            $sha = $this->http->get($release['sha_url'], $this->headers(), 30);
            $status = $sha['status'] === 200 ? $this->http->download($release['zip_url'], $this->headers(), $zip, 600) : $sha['status'];
        } catch (\Throwable $e) {
            $this->log->warning('update: download failed: {why}', ['why' => $e->getMessage()]);
            throw $this->fail('update.err_download');
        }
        if ($sha['status'] !== 200 || $status !== 200 || !is_file($zip)) {
            $this->log->warning('update: download failed', ['status' => $status]);
            throw $this->fail('update.err_download');
        }
        if (!preg_match('/^\s*([0-9a-fA-F]{64})\b/', $sha['body'], $m)
            || !hash_equals(strtolower($m[1]), (string) hash_file('sha256', $zip))) {
            $this->log->error('update: checksum mismatch', ['to' => $release['version']]);
            throw $this->fail('update.err_checksum');
        }
    }

    /** Entpackt das Paket nach $target; prueft Namen (kein Pfad-Ausbruch, kein data/), Groesse und Pflichtdateien. */
    private function extract(string $zipPath, string $target, string $version): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::RDONLY | \ZipArchive::CHECKCONS) !== true) {
            throw $this->fail('update.err_package');
        }
        try {
            if ($zip->numFiles < 1 || $zip->numFiles > self::MAX_FILES) {
                throw $this->fail('update.err_package');
            }
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $st = $zip->statIndex($i);
                if (!is_array($st) || !self::isSafeEntry((string) $st['name'])) {
                    $this->log->warning('update: rejected package entry', ['name' => is_array($st) ? (string) $st['name'] : '?']);
                    throw $this->fail('update.err_package');
                }
                $total += (int) $st['size'];
                if ($total > self::MAX_UNPACKED) {
                    throw $this->fail('update.err_package');
                }
            }
            if (!@mkdir($target, 0775, true) || !$zip->extractTo($target)) {
                throw $this->fail('update.err_package');
            }
        } finally {
            $zip->close();
        }
        $meta = json_decode((string) @file_get_contents($target . '/version.json'), true);
        if (!is_array($meta) || ($meta['version'] ?? null) !== $version
            || !is_file($target . '/api/index.php') || !is_file($target . '/app/vendor/autoload.php')) {
            throw $this->fail('update.err_package');
        }
    }

    /** Erlaubte Paketeintraege: relative Pfade ohne "..", ohne Backslash/Steuerzeichen, nie data/ oder setup.php. */
    public static function isSafeEntry(string $name): bool
    {
        if ($name === '' || str_starts_with($name, '/') || str_contains($name, '\\') || str_contains($name, ':')
            || preg_match('/[\x00-\x1f]/', $name)) {
            return false;
        }
        $parts = explode('/', rtrim($name, '/'));
        foreach ($parts as $p) {
            if ($p === '' || $p === '.' || $p === '..') {
                return false;
            }
        }
        return !in_array($parts[0], self::PROTECTED, true) && !str_starts_with($parts[0], self::STAGE_PREFIX);
    }

    /** Lokale Einstellungen (z. B. Datenordner-Pfad) ins neue app/ uebernehmen. */
    private function carryOver(string $new): void
    {
        foreach (self::CARRY_OVER as $file) {
            $src = $this->root . '/app/' . $file;
            if (is_file($src) && !@copy($src, $new . '/app/' . $file)) {
                throw $this->fail('update.err_replace');
            }
        }
    }

    /**
     * Tauscht jeden Eintrag der obersten Ebene aus $new gegen den bestehenden (alter -> $old).
     * @return list<array{name: string, had_old: bool}> erfolgreich ausgetauschte Eintraege (fuer Rollback)
     */
    private function swap(string $new, string $old): array
    {
        if (!@mkdir($old, 0775, true)) {
            throw $this->fail('update.err_replace');
        }
        $names = array_values(array_diff(scandir($new) ?: [], ['.', '..']));
        // Backend zuerst und direkt nacheinander, damit api/ und app/ moeglichst kurz auseinanderlaufen
        usort($names, static fn (string $a, string $b): int => [!in_array($a, ['app', 'api'], true), $a] <=> [!in_array($b, ['app', 'api'], true), $b]);
        $done = [];
        try {
            foreach ($names as $name) {
                $target = $this->root . '/' . $name;
                $hadOld = file_exists($target) || is_link($target);
                if ($hadOld && !$this->move($target, $old . '/' . $name)) {
                    throw new \RuntimeException('Alter Eintrag nicht verschiebbar: ' . $name);
                }
                if (!$this->move($new . '/' . $name, $target)) {
                    if ($hadOld) {
                        @rename($old . '/' . $name, $target);
                    }
                    throw new \RuntimeException('Neuer Eintrag nicht aktivierbar: ' . $name);
                }
                $done[] = ['name' => $name, 'had_old' => $hadOld];
            }
        } catch (\Throwable $e) {
            $this->log->error('update: replacing files failed, rolling back', ['exception' => $e]);
            $this->rollback($done, $old);
            throw $this->fail('update.err_replace');
        }
        return $done;
    }

    /** @param list<array{name: string, had_old: bool}> $done */
    private function rollback(array $done, string $old): void
    {
        foreach (array_reverse($done) as $item) {
            $target = $this->root . '/' . $item['name'];
            is_dir($target) && !is_link($target) ? BackupService::rmrf($target) : @unlink($target);
            if ($item['had_old'] && !@rename($old . '/' . $item['name'], $target)) {
                $this->keepStage = true;
                $this->log->critical('update: rollback could not restore entry', ['name' => $item['name']]);
            }
        }
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
        $this->log->warning('update: rolled back', ['entries' => count($done)]);
    }

    /** Rohkopie inkl. Anmeldungen (VACUUM INTO; Fallback Dateikopie). */
    private function copyDatabase(string $target): void
    {
        $pdo = $this->db->pdo();
        try {
            $pdo->exec('VACUUM INTO ' . $pdo->quote($target));
        } catch (\PDOException) {
            @unlink($target);
            $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
            if (!@copy($this->db->file(), $target)) {
                throw new \RuntimeException('Datenbankkopie fehlgeschlagen');
            }
        }
    }

    private function restoreDatabase(string $copy): void
    {
        if (!is_file($copy)) {
            return;
        }
        $file = $this->db->file();
        $this->db->close();
        @unlink($file . '-wal');
        @unlink($file . '-shm');
        if (!@copy($copy, $file . '.rollback') || !@rename($file . '.rollback', $file)) {
            $this->log->critical('update: database rollback failed');
        }
    }

    /** Nach dem Austausch: Version und Einstieg muessen zur erwarteten Fassung passen. */
    private function verify(string $to): void
    {
        $meta = json_decode((string) @file_get_contents($this->root . '/version.json'), true);
        if (!is_array($meta) || ($meta['version'] ?? null) !== $to || !is_file($this->root . '/app/vendor/autoload.php')) {
            throw new ApiException(500, 'update_failed', 'update.err_verify');
        }
    }

    private function prepareStage(string $stage): void
    {
        if (!@mkdir($stage, 0775, true)) {
            throw $this->fail('update.err_replace');
        }
        @file_put_contents($stage . '/.htaccess', "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
    }

    /** Reste abgebrochener Updates (> 1 h) entfernen. */
    private function cleanupStages(): void
    {
        foreach (glob($this->root . '/' . self::STAGE_PREFIX . '*', GLOB_ONLYDIR) ?: [] as $dir) {
            if ((int) @filemtime($dir) < $this->clock->now()->getTimestamp() - 3600) {
                BackupService::rmrf($dir);
            }
        }
    }

    /** @return list<string> */
    private function headers(): array
    {
        return ['User-Agent: Quitt/' . $this->config->version(), 'Accept: application/vnd.github+json'];
    }

    /** @return array{release: ?array}|null gueltiger Cache-Eintrag */
    private function readCache(): ?array
    {
        $data = json_decode((string) @file_get_contents($this->config->path(self::CACHE_FILE)), true);
        if (!is_array($data) || !isset($data['checked_at']) || !array_key_exists('release', $data)) {
            return null;
        }
        $ttl = !empty($data['error']) ? self::ERROR_TTL : (int) $this->config->get('update_check_ttl', 6 * 3600);
        $age = $this->clock->now()->getTimestamp() - (int) $data['checked_at'];
        if ($age < 0 || $age >= $ttl) {
            return null;
        }
        return ['release' => is_array($data['release']) ? $data['release'] : null];
    }

    private function writeCache(?array $release, bool $error): void
    {
        @file_put_contents(
            $this->config->path(self::CACHE_FILE),
            json_encode(['checked_at' => $this->clock->now()->getTimestamp(), 'error' => $error, 'release' => $release], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
    }

    private function fail(string $messageKey): ApiException
    {
        return new ApiException(500, 'update_failed', $messageKey);
    }
}
