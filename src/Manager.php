<?php

namespace Admnio\Sunset;

use Admnio\Sunset\RateLimiting\LimitBuilder;
use Admnio\Sunset\RateLimiting\LimitRegistry;
use Admnio\Sunset\RateLimiting\Targets\JobClassTarget;
use Admnio\Sunset\RateLimiting\Targets\QueueTarget;
use Illuminate\Contracts\Container\Container;

/**
 * Singleton that backs the Sunset facade. Public surface added in subsequent
 * minor releases:
 *   - v0.7.0 (this release): for() and limit() return LimitBuilder for rate-limit declarations.
 *   - v1.0.0 (planned): auth() will register the dashboard gate.
 */
class Manager
{
    public function __construct(private Container $container)
    {
    }

    public function for(string $queueName): LimitBuilder
    {
        return new LimitBuilder(
            $this->container->make(LimitRegistry::class),
            new QueueTarget($queueName),
            (array) $this->container['config']->get('sunset.rate_limits', []),
        );
    }

    public function limit(string $jobClass): LimitBuilder
    {
        return new LimitBuilder(
            $this->container->make(LimitRegistry::class),
            new JobClassTarget($jobClass),
            (array) $this->container['config']->get('sunset.rate_limits', []),
        );
    }
}
