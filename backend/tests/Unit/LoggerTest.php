<?php

declare(strict_types=1);

namespace Quitt\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Quitt\Log\Logger;
use Quitt\Log\Masker;
use Quitt\Support\FrozenClock;
use Quitt\Tests\Fixtures;

final class LoggerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/quitt-log-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            Fixtures::rmrf($this->dir);
        }
    }

    public function testWritesJsonLinesAndMasksSecrets(): void
    {
        $log = new Logger($this->dir, 'test', 'info', 6, new FrozenClock('2026-10-13T08:00:00Z'));
        $log->warning('login {who}', [
            'who' => 'anna', 'pin' => '1234', 'new_pin' => '5678', 'ai_key' => 'sk-geheim',
            'nested' => ['token' => 'abc', 'recovery_code' => 'XXXX'], 'url' => '/api/v1/x?ticket=t0p&y=1',
            'header' => 'Bearer abc.def',
        ]);
        $log->debug('nicht geschrieben');
        $lines = file($this->dir . '/quitt-2026-10.log');
        self::assertCount(1, $lines);
        $row = json_decode($lines[0], true);
        self::assertSame(['ts', 'level', 'channel', 'message', 'context'], array_keys($row));
        self::assertSame('warning', $row['level']);
        self::assertSame('test', $row['channel']);
        self::assertSame('login anna', $row['message']);
        foreach (['1234', '5678', 'sk-geheim', 'abc', 'XXXX', 't0p'] as $secret) {
            self::assertStringNotContainsString($secret, $lines[0]);
        }
        self::assertSame(Masker::MASK, $row['context']['pin']);
    }

    public function testKeepsAtMostMaxFiles(): void
    {
        $clock = new FrozenClock('2026-01-15T00:00:00Z');
        $log = new Logger($this->dir, 'test', 'info', 6, $clock);
        for ($i = 0; $i < 9; $i++) {
            $log->info('monat ' . $i);
            $clock->advance(31 * 86400);
        }
        $files = $log->files();
        self::assertCount(6, $files);
        self::assertStringEndsWith('quitt-2026-09.log', end($files));
    }
}
