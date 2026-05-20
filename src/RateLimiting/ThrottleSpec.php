<?php

namespace Admnio\Sunset\RateLimiting;

final class ThrottleSpec
{
    public function __construct(
        public readonly int $max,
        public readonly int $windowSeconds,
    ) {
        if ($max < 1) {
            throw new \InvalidArgumentException('ThrottleSpec max must be >= 1.');
        }
        if ($windowSeconds < 1) {
            throw new \InvalidArgumentException('ThrottleSpec windowSeconds must be >= 1.');
        }
    }
}
