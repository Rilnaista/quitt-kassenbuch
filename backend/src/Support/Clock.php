<?php

declare(strict_types=1);

namespace Quitt\Support;

use DateTimeImmutable;

/** Zeitquelle; in Tests durch FrozenClock ersetzbar (Rate-Limit, Ablaufzeiten). */
interface Clock
{
    public function now(): DateTimeImmutable;
}
