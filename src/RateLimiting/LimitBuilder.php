<?php

namespace Admnio\Sunset\RateLimiting;

use Admnio\Sunset\RateLimiting\Targets\JobClassTarget;
use Admnio\Sunset\RateLimiting\Targets\QueueTarget;
use Closure;

class LimitBuilder
{
    private ?ThrottleSpec $throttle = null;

    private ?ConcurrencySpec $concurrency = null;

    private ?Closure $keyResolver = null;

    private ?Closure $condition = null;

    private string $overLimit = 'release-computed';

    private ?int $fixedBackoffSeconds = null;

    private bool $dropAsFailure = true;

    private ?bool $countReleases = null;

    public function __construct(
        private LimitRegistry $registry,
        private QueueTarget|JobClassTarget $target,
        private array $rateLimitConfig,
    ) {
    }

    /**
     * Set a sliding-window throttle. Pick exactly one of perSecond/perMinute/perHour/perDay,
     * or use the raw form throttle($max, per: $windowSeconds).
     */
    public function throttle(
        ?int $max = null,
        ?int $per = null,
        ?int $perSecond = null,
        ?int $perMinute = null,
        ?int $perHour = null,
        ?int $perDay = null,
    ): self {
        [$resolvedMax, $resolvedWindow] = $this->normalizeThrottle(
            $max, $per, $perSecond, $perMinute, $perHour, $perDay
        );
        $this->throttle = new ThrottleSpec($resolvedMax, $resolvedWindow);
        $this->commitToRegistry();

        return $this;
    }

    public function concurrency(int $max, ?int $slotTtl = null): self
    {
        $resolvedTtl = $slotTtl ?? $this->defaultSlotTtl();
        $this->concurrency = new ConcurrencySpec($max, $resolvedTtl);
        $this->commitToRegistry();

        return $this;
    }

    public function by(Closure $key): self
    {
        $this->keyResolver = $key;
        $this->commitToRegistry();

        return $this;
    }

    public function when(Closure $condition): self
    {
        $this->condition = $condition;
        $this->commitToRegistry();

        return $this;
    }

    /**
     * @param  'release-computed'|'release-fixed'|'drop'  $strategy
     */
    public function onOverLimit(string $strategy): self
    {
        $this->overLimit = $strategy;
        $this->commitToRegistry();

        return $this;
    }

    public function releaseAfter(int $seconds): self
    {
        $this->overLimit = 'release-fixed';
        $this->fixedBackoffSeconds = $seconds;
        $this->commitToRegistry();

        return $this;
    }

    public function dropAsFailure(bool $asFailure = true): self
    {
        $this->dropAsFailure = $asFailure;
        $this->commitToRegistry();

        return $this;
    }

    public function countReleases(bool $count = true): self
    {
        $this->countReleases = $count;
        $this->commitToRegistry();

        return $this;
    }

    /**
     * @return array{0:int,1:int}
     */
    private function normalizeThrottle(?int $max, ?int $per, ?int $perSecond, ?int $perMinute, ?int $perHour, ?int $perDay): array
    {
        if ($perSecond !== null) {
            return [$perSecond, 1];
        }
        if ($perMinute !== null) {
            return [$perMinute, 60];
        }
        if ($perHour !== null) {
            return [$perHour, 3600];
        }
        if ($perDay !== null) {
            return [$perDay, 86400];
        }
        if ($max !== null && $per !== null) {
            return [$max, $per];
        }
        throw new \InvalidArgumentException(
            'throttle() needs a per{Second,Minute,Hour,Day}: N argument or the raw ($max, per: $seconds) form.'
        );
    }

    private function defaultSlotTtl(): int
    {
        $connections = config('queue.connections', []);
        if (! is_array($connections)) {
            $connections = [];
        }
        $maxRetryAfter = 60;
        foreach ($connections as $cfg) {
            if (is_array($cfg) && ! empty($cfg['retry_after']) && $cfg['retry_after'] > $maxRetryAfter) {
                $maxRetryAfter = (int) $cfg['retry_after'];
            }
        }

        return $maxRetryAfter + 60;
    }

    private function commitToRegistry(): void
    {
        $name = $this->target instanceof QueueTarget
            ? "queue:{$this->target->queueName}"
            : "class:{$this->target->jobClass}";

        $defaultCountReleases = $this->rateLimitConfig['count_releases_by_default'] ?? false;

        $limit = new Limit(
            name: $name,
            target: $this->target,
            throttle: $this->throttle,
            concurrency: $this->concurrency,
            keyResolver: $this->keyResolver,
            condition: $this->condition,
            overLimit: $this->overLimit,
            fixedBackoffSeconds: $this->fixedBackoffSeconds,
            dropAsFailure: $this->dropAsFailure,
            countReleases: $this->countReleases ?? (bool) $defaultCountReleases,
        );

        $this->registry->upsert($limit);
    }
}
