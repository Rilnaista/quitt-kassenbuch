<?php

declare(strict_types=1);

namespace Quitt\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Quitt\Db\Database;
use Quitt\Http\ApiException;
use Quitt\Service\RateLimiter;
use Quitt\Tests\TestHelpers;

final class RateLimiterTest extends TestCase
{
    use TestHelpers;

    protected function setUp(): void
    {
        $this->bootContainer();
        $this->migrate();
    }

    protected function tearDown(): void
    {
        $this->c->get(Database::class)->close();
        $this->cleanupDataDir();
    }

    public function testExponentialLockFromFifthFailureCappedAtOneHour(): void
    {
        $l = $this->c->get('limiter.login');
        $locks = [];
        for ($i = 1; $i <= 14; $i++) {
            $locks[$i] = $l->fail();
        }
        self::assertSame([0, 0, 0, 0], array_values(array_slice($locks, 0, 4)));
        self::assertSame(30, $locks[5]);
        self::assertSame(60, $locks[6]);
        self::assertSame(120, $locks[7]);
        self::assertSame(960, $locks[10], '10 Fehlversuche -> spuerbare Wartezeit');
        self::assertSame(3600, $locks[14]);
        self::assertSame(3600, $l->retryAfter());
    }

    public function testLockExpiresAndResetClears(): void
    {
        /** @var RateLimiter $l */
        $l = $this->c->get('limiter.login');
        for ($i = 0; $i < 5; $i++) {
            $l->fail();
        }
        try {
            $l->assertNotLocked();
            self::fail('gesperrt erwartet');
        } catch (ApiException $e) {
            self::assertSame(429, $e->status);
            self::assertSame(30, $e->extra['retry_after']);
        }
        $this->clock->advance(31);
        $l->assertNotLocked();
        $l->reset();
        self::assertSame(0, $l->failures());
    }
}
