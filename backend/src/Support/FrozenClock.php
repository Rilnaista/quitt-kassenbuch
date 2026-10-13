<?php

declare(strict_types=1);

namespace Quitt\Support;

use DateTimeImmutable;
use DateTimeZone;

/** Feste, manuell vorstellbare Uhr (Tests, Simulation). */
final class FrozenClock implements Clock
{
    private DateTimeImmutable $now;

    public function __construct(string $at = '2026-01-01T12:00:00Z')
    {
        $this->now = (new DateTimeImmutable($at))->setTimezone(new DateTimeZone('UTC'));
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(int $seconds): void
    {
        $this->now = $this->now->modify(($seconds >= 0 ? '+' : '') . $seconds . ' seconds');
    }
}
