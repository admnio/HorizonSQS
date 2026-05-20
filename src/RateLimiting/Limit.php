<?php

namespace Admnio\Sunset\RateLimiting;

use Admnio\Sunset\RateLimiting\Targets\JobClassTarget;
use Admnio\Sunset\RateLimiting\Targets\QueueTarget;
use Closure;

final class Limit
{
    /**
     * @param 'release-computed'|'release-fixed'|'drop' $overLimit
     */
    public function __construct(
        public readonly string $name,
        public readonly QueueTarget|JobClassTarget $target,
        public readonly ?ThrottleSpec $throttle = null,
        public readonly ?ConcurrencySpec $concurrency = null,
        public readonly ?Closure $keyResolver = null,
        public readonly ?Closure $condition = null,
        public readonly string $overLimit = 'release-computed',
        public readonly ?int $fixedBackoffSeconds = null,
        public readonly bool $dropAsFailure = true,
        public readonly bool $countReleases = false,
    ) {
        if ($throttle === null && $concurrency === null) {
            throw new \InvalidArgumentException("Limit '{$name}' must define at least one of throttle/concurrency.");
        }
        if (! in_array($overLimit, ['release-computed', 'release-fixed', 'drop'], true)) {
            throw new \InvalidArgumentException("Limit '{$name}' has invalid overLimit '{$overLimit}'.");
        }
        if ($overLimit === 'release-fixed' && $fixedBackoffSeconds === null) {
            throw new \InvalidArgumentException("Limit '{$name}' uses release-fixed but has no fixedBackoffSeconds.");
        }
    }
}
