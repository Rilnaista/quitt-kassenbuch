<?php

declare(strict_types=1);

namespace Quitt\Tests;

use Quitt\App\Bootstrap;
use Quitt\App\Config;
use Quitt\App\Container;
use Quitt\App\DataDir;
use Quitt\Db\Migrator;
use Quitt\Support\FrozenClock;

/** Gemeinsame Test-Infrastruktur: frischer Datenordner + Container je Test. */
trait TestHelpers
{
    protected string $dataDir;
    protected Container $c;
    protected FrozenClock $clock;

    protected function bootContainer(array $configValues = []): void
    {
        $this->dataDir = sys_get_temp_dir() . '/quitt-test-' . bin2hex(random_bytes(6));
        DataDir::ensure($this->dataDir);
        $this->clock = new FrozenClock('2026-10-13T08:00:00Z');
        $this->c = Bootstrap::container(new Config(self::appDir(), $this->dataDir, $configValues), $this->clock);
    }

    protected function migrate(): void
    {
        $this->c->get(Migrator::class)->migrate();
    }

    protected function cleanupDataDir(): void
    {
        if (isset($this->dataDir) && is_dir($this->dataDir)) {
            Fixtures::rmrf($this->dataDir);
        }
    }

    public static function appDir(): string
    {
        return Fixtures::appDir();
    }

    public static function uuid(int $n): string
    {
        return Fixtures::uuid($n);
    }

    public static function entry(int $n, array $over = []): array
    {
        return Fixtures::entry($n, $over);
    }

    public static function jpeg(string $salt = ''): string
    {
        return Fixtures::jpeg($salt);
    }
}
