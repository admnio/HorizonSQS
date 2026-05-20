<?php

namespace Admnio\Sunset\Tests\Unit\RateLimiting;

use Admnio\Sunset\RateLimiting\ConcurrencySpec;
use Admnio\Sunset\RateLimiting\Limit;
use Admnio\Sunset\RateLimiting\Targets\JobClassTarget;
use Admnio\Sunset\RateLimiting\Targets\QueueTarget;
use Admnio\Sunset\RateLimiting\ThrottleSpec;
use Admnio\Sunset\Tests\TestCase;
use InvalidArgumentException;

class LimitTest extends TestCase
{
    public function test_limit_carries_fields_and_defaults(): void
    {
        $target = new QueueTarget('geocode');
        $throttle = new ThrottleSpec(10, 60);
        $concurrency = new ConcurrencySpec(3, 120);

        $limit = new Limit(
            name: 'geocode-rate',
            target: $target,
            throttle: $throttle,
            concurrency: $concurrency,
        );

        $this->assertSame('geocode-rate', $limit->name);
        $this->assertSame($target, $limit->target);
        $this->assertSame($throttle, $limit->throttle);
        $this->assertSame($concurrency, $limit->concurrency);
        $this->assertNull($limit->keyResolver);
        $this->assertNull($limit->condition);
        $this->assertSame('release-computed', $limit->overLimit);
        $this->assertNull($limit->fixedBackoffSeconds);
        $this->assertTrue($limit->dropAsFailure);
        $this->assertFalse($limit->countReleases);
    }

    public function test_job_class_target_stores_class_string(): void
    {
        $target = new JobClassTarget('App\\Jobs\\Geo');

        $this->assertSame('App\\Jobs\\Geo', $target->jobClass);
    }

    public function test_queue_target_stores_queue_name(): void
    {
        $target = new QueueTarget('geocode');

        $this->assertSame('geocode', $target->queueName);
    }

    public function test_limit_requires_at_least_one_of_throttle_or_concurrency(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Limit(
            name: 'empty',
            target: new QueueTarget('q'),
        );
    }

    public function test_limit_rejects_invalid_over_limit_string(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Limit(
            name: 'bad',
            target: new QueueTarget('q'),
            throttle: new ThrottleSpec(1, 1),
            overLimit: 'explode',
        );
    }

    public function test_limit_release_fixed_requires_fixed_backoff_seconds(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Limit(
            name: 'fixed-missing',
            target: new QueueTarget('q'),
            throttle: new ThrottleSpec(1, 1),
            overLimit: 'release-fixed',
        );
    }
}
