<?php

declare(strict_types=1);

namespace Quitt\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Quitt\App\Bootstrap;
use Quitt\App\Config;
use Quitt\App\Container;
use Quitt\App\DataDir;
use Quitt\Db\Database;
use Quitt\Db\Migrator;
use Quitt\Http\ApiException;
use Quitt\Log\Logger;
use Quitt\Service\AuthService;
use Quitt\Service\BackupService;
use Quitt\Service\InstallService;
use Quitt\Service\SettingsService;
use Quitt\Service\SyncService;
use Quitt\Service\UpdateService;
use Quitt\Support\FrozenClock;
use Quitt\Tests\FakeHttpClient;
use Quitt\Tests\Fixtures;

/**
 * Update-Service gegen ein Fake-Release-Layout im Temp-Ordner (app/, api/, data/, version.json)
 * und ein lokal gebautes Fake-Paket; GitHub-Antworten gemockt.
 */
final class UpdateServiceTest extends TestCase
{
    private const API = 'https://api.github.com/repos/Rilnaista/quitt-kassenbuch/releases?per_page=30';
    private const ZIP_URL = 'https://github.com/Rilnaista/quitt-kassenbuch/releases/download/v1.1.0/quitt-1.1.0.zip';
    private const SHA_URL = self::ZIP_URL . '.sha256';

    private string $root;
    private Container $c;
    private FrozenClock $clock;
    private FakeHttpClient $http;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/quitt-upd-' . bin2hex(random_bytes(6));
        $app = $this->root . '/app';
        mkdir($app . '/vendor', 0775, true);
        mkdir($this->root . '/api', 0775, true);
        self::copyDir(Fixtures::appDir() . '/migrations', $app . '/migrations');
        self::copyDir(Fixtures::appDir() . '/lang', $app . '/lang');
        file_put_contents($app . '/VERSION', "1.0.0\n");
        file_put_contents($app . '/vendor/autoload.php', "<?php // alt\n");
        file_put_contents($app . '/config.local.php', "<?php return [];\n");
        file_put_contents($app . '/nur-alt.txt', 'alt');
        file_put_contents($this->root . '/api/index.php', "<?php // alt\n");
        file_put_contents($this->root . '/index.html', 'ALT');
        file_put_contents($this->root . '/version.json', '{"version":"1.0.0","schema":1}');
        file_put_contents($this->root . '/setup.php', 'SETUP');

        DataDir::ensure($this->root . '/data');
        $this->clock = new FrozenClock('2026-10-13T08:00:00Z');
        $this->c = Bootstrap::container(new Config($app, $this->root . '/data'), $this->clock);
        $this->http = new FakeHttpClient();

        $this->c->get(InstallService::class)->install('Testkasse', '1234');
        $this->c->get(SyncService::class)->apply(['entries' => [Fixtures::entry(1), Fixtures::entry(2)]]);
        $this->c->get(AuthService::class)->login('1234');
    }

    protected function tearDown(): void
    {
        $this->c->get(Database::class)->close();
        Fixtures::rmrf($this->root);
    }

    private function service(bool $docker = false, ?int $failOnMove = null): UpdateService
    {
        $c = $this->c;
        $args = [
            $c->get(Config::class), $c->get(BackupService::class), $c->get(Migrator::class), $c->get(Database::class),
            $this->http, $this->clock, $c->get(Logger::class)->withChannel('update'), $docker,
        ];
        if ($failOnMove === null) {
            return new UpdateService(...$args);
        }
        return new class ($failOnMove, ...$args) extends UpdateService {
            private int $n = 0;

            public function __construct(private readonly int $failOn, mixed ...$args)
            {
                parent::__construct(...$args);
            }

            protected function move(string $from, string $to): bool
            {
                return ++$this->n === $this->failOn ? false : parent::move($from, $to);
            }
        };
    }

    /** Fake-Paket 1.1.0 bauen; $extraMigrations: Dateiname => PHP-Code */
    private function package(array $extraMigrations = [], string $version = '1.1.0'): string
    {
        $zipFile = $this->root . '/../quitt-pkg-' . bin2hex(random_bytes(4)) . '.zip';
        $zip = new \ZipArchive();
        $zip->open($zipFile, \ZipArchive::CREATE);
        $zip->addFromString('version.json', json_encode(['version' => $version, 'schema' => 2]));
        $zip->addFromString('index.html', 'NEU');
        $zip->addFromString('assets/app.js', 'console.log(1)');
        $zip->addFromString('.htaccess', '# neu');
        $zip->addFromString('api/index.php', "<?php // neu\n");
        $zip->addFromString('app/VERSION', $version . "\n");
        $zip->addFromString('app/vendor/autoload.php', "<?php // neu\n");
        foreach (glob(Fixtures::appDir() . '/migrations/*.php') as $f) {
            $zip->addFile($f, 'app/migrations/' . basename($f));
        }
        foreach ($extraMigrations as $name => $code) {
            $zip->addFromString('app/migrations/' . $name, $code);
        }
        foreach (glob(Fixtures::appDir() . '/lang/*.php') as $f) {
            $zip->addFile($f, 'app/lang/' . basename($f));
        }
        $zip->close();
        $bytes = (string) file_get_contents($zipFile);
        unlink($zipFile);
        return $bytes;
    }

    private function publish(string $zipBytes, ?string $sha = null, string $version = '1.1.0'): void
    {
        $this->http->on(self::API, 200, [
            ['tag_name' => 'v0.9.0', 'draft' => false, 'prerelease' => false, 'assets' => []],
            ['tag_name' => 'v2.0.0', 'draft' => true, 'prerelease' => false, 'assets' => []],
            [
                'tag_name' => 'v' . $version, 'draft' => false, 'prerelease' => true, 'body' => 'Neu: alles', 'published_at' => '2026-10-12T10:00:00Z',
                'assets' => [
                    ['name' => 'quitt-' . $version . '.zip', 'browser_download_url' => self::ZIP_URL],
                    ['name' => 'quitt-' . $version . '.zip.sha256', 'browser_download_url' => self::SHA_URL],
                    ['name' => 'setup.php', 'browser_download_url' => 'https://example.invalid/setup.php'],
                ],
            ],
        ]);
        $this->http->on(self::ZIP_URL, 200, $zipBytes);
        $this->http->on(self::SHA_URL, 200, ($sha ?? hash('sha256', $zipBytes)) . "  quitt-$version.zip\n");
    }

    private function countEntries(): int
    {
        return (int) $this->c->get(Database::class)->scalar('SELECT COUNT(*) FROM entries');
    }

    private function assertOldStateIntact(): void
    {
        self::assertSame('ALT', file_get_contents($this->root . '/index.html'));
        self::assertSame("1.0.0\n", file_get_contents($this->root . '/app/VERSION'));
        self::assertSame("<?php // alt\n", file_get_contents($this->root . '/api/index.php'));
        self::assertFileExists($this->root . '/app/nur-alt.txt');
        self::assertFileDoesNotExist($this->root . '/assets/app.js');
        self::assertSame(2, $this->countEntries());
        self::assertSame([], glob($this->root . '/' . UpdateService::STAGE_PREFIX . '*'), 'Temp-Ordner nicht aufgeraeumt');
    }

    private function applyExpectingFailure(UpdateService $svc, string $messageKey): void
    {
        try {
            $svc->apply();
            self::fail('Update haette fehlschlagen muessen');
        } catch (ApiException $e) {
            self::assertSame(500, $e->status);
            self::assertSame('update_failed', $e->errorCode);
            self::assertSame($messageKey, $e->messageKey);
        }
    }

    public function testCheckPicksHighestReleaseIncludingPrereleaseAndCaches(): void
    {
        $this->publish($this->package());
        $r = $this->service()->check();
        self::assertSame(['current' => '1.0.0', 'latest' => '1.1.0', 'available' => true, 'notes' => 'Neu: alles',
            'published_at' => '2026-10-12T10:00:00Z', 'docker' => false], $r);
        self::assertCount(1, $this->http->calls);

        $this->service()->check();
        self::assertCount(1, $this->http->calls, 'Ergebnis muss gecacht sein');
        $this->clock->advance(6 * 3600);
        $this->service()->check();
        self::assertCount(2, $this->http->calls, 'Cache laeuft nach 6 h ab');
    }

    public function testCheckFailsSilently(): void
    {
        $this->http->failOn(self::API);
        $r = $this->service(true)->check();
        self::assertFalse($r['available']);
        self::assertNull($r['latest']);
        self::assertTrue($r['docker']);
        $log = implode('', array_map('file_get_contents', glob($this->root . '/data/logs/*.log')));
        self::assertStringContainsString('update check failed', $log);
    }

    public function testPickReleaseIgnoresMissingAssetsAndBadTags(): void
    {
        self::assertNull(UpdateService::pickRelease([['tag_name' => 'v9.9.9', 'assets' => [['name' => 'quitt-9.9.9.zip', 'browser_download_url' => 'https://x/a.zip']]]]));
        self::assertNull(UpdateService::pickRelease([['tag_name' => 'latest', 'assets' => []]]));
        $two = UpdateService::pickRelease([
            ['tag_name' => 'v1.0.0-rc1', 'assets' => [['name' => 'quitt-1.0.0-rc1.zip', 'browser_download_url' => 'https://x/1'], ['name' => 'quitt-1.0.0-rc1.zip.sha256', 'browser_download_url' => 'https://x/1s']]],
            ['tag_name' => 'v1.0.0', 'assets' => [['name' => 'quitt-1.0.0.zip', 'browser_download_url' => 'https://x/2'], ['name' => 'quitt-1.0.0.zip.sha256', 'browser_download_url' => 'https://x/2s']]],
        ]);
        self::assertSame('1.0.0', $two['version']);
    }

    public function testSafeEntryNames(): void
    {
        foreach (['../x', '/etc/passwd', 'a/../../b', 'data/quitt.sqlite', 'data', 'setup.php', 'a\\b', 'C:/x', '.quitt-update-x/a'] as $bad) {
            self::assertFalse(UpdateService::isSafeEntry($bad), $bad);
        }
        foreach (['index.html', 'app/', 'app/src/A.php', '.htaccess', 'api/docs/index.html'] as $ok) {
            self::assertTrue(UpdateService::isSafeEntry($ok), $ok);
        }
    }

    public function testApplySuccessKeepsDataAndRunsMigrations(): void
    {
        $this->publish($this->package(['0900_update_test.php' => "<?php\nreturn static function (PDO \$pdo): void { \$pdo->exec('CREATE TABLE update_test (id INTEGER)'); };\n"]));
        $r = $this->service()->apply();
        self::assertSame(['ok' => true, 'from' => '1.0.0', 'to' => '1.1.0'], $r);

        self::assertSame('NEU', file_get_contents($this->root . '/index.html'));
        self::assertSame("1.1.0\n", file_get_contents($this->root . '/app/VERSION'));
        self::assertFileExists($this->root . '/assets/app.js');
        self::assertFileDoesNotExist($this->root . '/app/nur-alt.txt', 'app/ wird vollstaendig ersetzt');
        self::assertFileExists($this->root . '/app/config.local.php', 'config.local.php wird uebernommen');
        self::assertSame('SETUP', file_get_contents($this->root . '/setup.php'), 'setup.php bleibt unberuehrt');
        self::assertSame(2, $this->countEntries());
        self::assertTrue($this->c->get(Database::class)->tableExists('update_test'), 'neue Migration angewendet');
        self::assertSame('Testkasse', $this->c->get(SettingsService::class)->get('group_name'));
        self::assertSame(1, (int) $this->c->get(Database::class)->scalar('SELECT COUNT(*) FROM auth_tokens'), 'Anmeldungen bleiben');
        $reasons = array_column($this->c->get(BackupService::class)->list(), 'reason');
        self::assertContains('before_update', $reasons);
        self::assertSame([], glob($this->root . '/' . UpdateService::STAGE_PREFIX . '*'));
        self::assertFileDoesNotExist($this->root . '/data/' . UpdateService::CACHE_FILE);
    }

    public function testWrongChecksumChangesNothing(): void
    {
        $this->publish($this->package(), str_repeat('ab', 32));
        $this->applyExpectingFailure($this->service(), 'update.err_checksum');
        $this->assertOldStateIntact();
    }

    public function testFailureWhileReplacingRollsBack(): void
    {
        $this->publish($this->package());
        // Aufrufe von move(): 1/2 = api alt/neu, 3/4 = app alt/neu, 5 = .htaccess neu, 6 = assets neu -> Fehler mitten im Austausch
        $this->applyExpectingFailure($this->service(false, 6), 'update.err_replace');
        $this->assertOldStateIntact();
        self::assertFileDoesNotExist($this->root . '/.htaccess', 'neu angelegte Eintraege werden entfernt');
    }

    public function testFailingMigrationRollsBackFilesAndDatabase(): void
    {
        $this->publish($this->package([
            '0900_ok.php' => "<?php\nreturn static function (PDO \$pdo): void { \$pdo->exec('CREATE TABLE schritt_eins (id INTEGER)'); };\n",
            '0901_kaputt.php' => "<?php\nreturn static function (PDO \$pdo): void { throw new RuntimeException('kaputt'); };\n",
        ]));
        $this->applyExpectingFailure($this->service(), 'update.err_migration');
        $this->assertOldStateIntact();
        $db = $this->c->get(Database::class);
        self::assertFalse($db->tableExists('schritt_eins'), 'Datenbank auf alten Stand zurueckgesetzt');
        self::assertSame(['0001'], $this->c->get(Migrator::class)->applied());
        self::assertSame(1, (int) $db->scalar('SELECT COUNT(*) FROM auth_tokens'), 'Anmeldungen bleiben nach Rollback');
    }

    public function testNoNewerVersion(): void
    {
        $this->publish($this->package([], '1.0.0'), null, '1.0.0');
        $this->applyExpectingFailure($this->service(), 'update.err_no_update');
    }

    public function testDockerIsManagedByImage(): void
    {
        try {
            $this->service(true)->apply();
            self::fail('409 erwartet');
        } catch (ApiException $e) {
            self::assertSame(409, $e->status);
            self::assertSame('docker_managed', $e->errorCode);
        }
        self::assertSame([], $this->http->calls);
    }

    public function testRefusesOutsideReleaseLayout(): void
    {
        unlink($this->root . '/version.json');
        $this->applyExpectingFailure($this->service(), 'update.err_not_release');
    }

    private static function copyDir(string $from, string $to): void
    {
        mkdir($to, 0775, true);
        foreach (glob($from . '/*') as $f) {
            copy($f, $to . '/' . basename($f));
        }
    }
}
