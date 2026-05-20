<?php

namespace Admnio\Sunset\RateLimiting;

final class ConcurrencySpec
{
    public function __construct(
        public readonly int $max,
        public readonly int $slotTtlSeconds,
    ) {
        if ($max < 1) {
            throw new \InvalidArgumentException('ConcurrencySpec max must be >= 1.');
        }
        if ($slotTtlSeconds < 1) {
            throw new \InvalidArgumentException('ConcurrencySpec slotTtlSeconds must be >= 1.');
        }
    }
}
