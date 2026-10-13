<?php

declare(strict_types=1);

namespace Quitt\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Quitt\Db\Database;
use Quitt\Http\ApiException;
use Quitt\Service\AuthService;
use Quitt\Service\InstallService;
use Quitt\Service\SettingsService;
use Quitt\Tests\TestHelpers;

final class InstallServiceTest extends TestCase
{
    use TestHelpers;

    protected function setUp(): void
    {
        $this->bootContainer();
    }

    protected function tearDown(): void
    {
        $this->c->get(Database::class)->close();
        $this->cleanupDataDir();
    }

    public function testInstallSetsPinGroupAndRecoveryCode(): void
    {
        $install = $this->c->get(InstallService::class);
        self::assertFalse($install->isInstalled());
        $code = $install->install('  Elternrat Sonnenschein  ', '123456');
        self::assertMatchesRegularExpression('/^[A-Z0-9]{4}(-[A-Z0-9]{4}){3}$/', $code);
        self::assertTrue($install->isInstalled());
        $auth = $this->c->get(AuthService::class);
        self::assertTrue($auth->verifyPin('123456'));
        self::assertTrue($auth->verifyRecoveryCode($code));
        self::assertSame('Elternrat Sonnenschein', $this->c->get(SettingsService::class)->get('group_name'));

        $db = $this->c->get(Database::class);
        $raw = implode('|', array_column($db->all('SELECT value FROM meta'), 'value'));
        self::assertStringNotContainsString('123456', $raw, 'PIN nur als Hash');
        self::assertStringNotContainsString($code, $raw, 'Code nur als Hash');

        $this->expectException(\RuntimeException::class);
        $install->install('Nochmal', '1234');
    }

    public function testInstallValidates(): void
    {
        try {
            $this->c->get(InstallService::class)->install('', '12a4');
            self::fail('422 erwartet');
        } catch (ApiException $e) {
            self::assertSame(['group_name', 'pin'], array_keys($e->extra['fields']));
        }
        self::assertFalse($this->c->get(InstallService::class)->isInstalled());
    }

    public function testCliScript(): void
    {
        $cmd = sprintf('QUITT_DATA_DIR=%s %s %s %s %s 2>&1', escapeshellarg($this->dataDir), escapeshellarg(PHP_BINARY),
            escapeshellarg(self::appDir() . '/bin/install.php'), escapeshellarg('CLI-Kasse'), '4711');
        exec($cmd, $out, $rc);
        self::assertSame(0, $rc, implode("\n", $out));
        self::assertMatchesRegularExpression('/Wiederherstellungscode: [A-Z0-9-]{19}/', implode("\n", $out));
        exec($cmd, $out2, $rc2);
        self::assertSame(1, $rc2, 'zweite Installation wird abgelehnt');
    }
}
