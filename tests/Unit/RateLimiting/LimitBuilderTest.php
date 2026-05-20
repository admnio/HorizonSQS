<?php

namespace Admnio\Sunset\Tests\Unit\RateLimiting;

use Admnio\Sunset\RateLimiting\LimitBuilder;
use Admnio\Sunset\RateLimiting\LimitRegistry;
use Admnio\Sunset\RateLimiting\Targets\JobClassTarget;
use Admnio\Sunset\RateLimiting\Targets\QueueTarget;
use Admnio\Sunset\Tests\TestCase;
use InvalidArgumentException;

class LimitBuilderTest extends TestCase
{
    private LimitRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registry = new LimitRegistry();
    }

    private function builderForQueue(string $queue, array $rateLimitConfig = []): LimitBuilder
    {
        return new LimitBuilder($this->registry, new QueueTarget($queue), $rateLimitConfig);
    }

    private function builderForClass(string $class, array $rateLimitConfig = []): LimitBuilder
    {
        return new LimitBuilder($this->registry, new JobClassTarget($class), $rateLimitConfig);
    }

    public function test_throttle_per_minute_registers_limit_with_window_60(): void
    {
        $this->builderForQueue('geocode')->throttle(perMinute: 10);

        $limits = $this->registry->all();
        $this->assertCount(1, $limits);
        $limit = $limits[0];
        $this->assertSame('queue:geocode', $limit->name);
        $this->assertNotNull($limit->throttle);
        $this->assertSame(10, $limit->throttle->max);
        $this->assertSame(60, $limit->throttle->windowSeconds);
    }

    public function test_throttle_per_second_registers_with_window_1(): void
    {
        $this->builderForQueue('q')->throttle(perSecond: 4);

        $limit = $this->registry->all()[0];
        $this->assertSame(4, $limit->throttle->max);
        $this->assertSame(1, $limit->throttle->windowSeconds);
    }

    public function test_throttle_per_hour_registers_with_window_3600(): void
    {
        $this->builderForQueue('q')->throttle(perHour: 100);

        $limit = $this->registry->all()[0];
        $this->assertSame(100, $limit->throttle->max);
        $this->assertSame(3600, $limit->throttle->windowSeconds);
    }

    public function test_throttle_per_day_registers_with_window_86400(): void
    {
        $this->builderForQueue('q')->throttle(perDay: 1000);

        $limit = $this->registry->all()[0];
        $this->assertSame(1000, $limit->throttle->max);
        $this->assertSame(86400, $limit->throttle->windowSeconds);
    }

    public function test_throttle_raw_form_registers_provided_values(): void
    {
        $this->builderForQueue('q')->throttle(5, per: 10);

        $limit = $this->registry->all()[0];
        $this->assertSame(5, $limit->throttle->max);
        $this->assertSame(10, $limit->throttle->windowSeconds);
    }

    public function test_throttle_with_no_arguments_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->builderForQueue('q')->throttle();
    }

    public function test_concurrency_uses_default_slot_ttl_from_queue_retry_after(): void
    {
        // Orchestra/Testbench ships defaults including beanstalkd & database with
        // retry_after = 90; LimitBuilder picks the max + 60 = 150.
        $this->builderForQueue('q')->concurrency(3);

        $limit = $this->registry->all()[0];
        $this->assertNotNull($limit->concurrency);
        $this->assertSame(3, $limit->concurrency->max);
        $this->assertSame(150, $limit->concurrency->slotTtlSeconds);
    }

    public function test_concurrency_with_only_60s_connections_uses_120_default_slot_ttl(): void
    {
        config()->set('queue.connections', [
            'redis' => ['driver' => 'redis', 'retry_after' => 60],
            'sqs' => ['driver' => 'sqs'],
        ]);

        $this->builderForQueue('q')->concurrency(2);

        $limit = $this->registry->all()[0];
        $this->assertSame(120, $limit->concurrency->slotTtlSeconds);
    }

    public function test_concurrency_explicit_slot_ttl_overrides_default(): void
    {
        $this->builderForQueue('q')->concurrency(3, slotTtl: 300);

        $limit = $this->registry->all()[0];
        $this->assertSame(300, $limit->concurrency->slotTtlSeconds);
    }

    public function test_release_after_sets_release_fixed_strategy_and_seconds(): void
    {
        $this->builderForQueue('q')->throttle(perMinute: 1)->releaseAfter(30);

        $limit = $this->registry->all()[0];
        $this->assertSame('release-fixed', $limit->overLimit);
        $this->assertSame(30, $limit->fixedBackoffSeconds);
    }

    public function test_on_over_limit_drop_with_drop_as_failure_false(): void
    {
        $this->builderForQueue('q')
            ->throttle(perMinute: 1)
            ->onOverLimit('drop')
            ->dropAsFailure(false);

        $limit = $this->registry->all()[0];
        $this->assertSame('drop', $limit->overLimit);
        $this->assertFalse($limit->dropAsFailure);
    }

    public function test_job_class_target_builds_limit_named_by_class(): void
    {
        $this->builderForClass('App\\Jobs\\Geocode')->throttle(perMinute: 5);

        $limit = $this->registry->all()[0];
        $this->assertSame('class:App\\Jobs\\Geocode', $limit->name);
        $this->assertInstanceOf(JobClassTarget::class, $limit->target);
        $this->assertSame('App\\Jobs\\Geocode', $limit->target->jobClass);
    }

    public function test_by_resolver_and_when_condition_are_persisted(): void
    {
        $keyFn = fn ($job, $payload) => 'k';
        $whenFn = fn ($job, $payload) => true;

        $this->builderForQueue('q')
            ->throttle(perMinute: 1)
            ->by($keyFn)
            ->when($whenFn);

        $limit = $this->registry->all()[0];
        $this->assertSame($keyFn, $limit->keyResolver);
        $this->assertSame($whenFn, $limit->condition);
    }

    public function test_count_releases_default_pulled_from_rate_limit_config(): void
    {
        $b = $this->builderForQueue('q', ['count_releases_by_default' => true]);
        $b->throttle(perMinute: 1);

        $limit = $this->registry->all()[0];
        $this->assertTrue($limit->countReleases);
    }

    public function test_count_releases_override_wins_over_config_default(): void
    {
        $b = $this->builderForQueue('q', ['count_releases_by_default' => true]);
        $b->throttle(perMinute: 1)->countReleases(false);

        $limit = $this->registry->all()[0];
        $this->assertFalse($limit->countReleases);
    }

    public function test_default_slot_ttl_falls_back_to_120_when_no_queue_configs(): void
    {
        // Clear all queue.connections to force fallback path.
        config()->set('queue.connections', []);

        $this->builderForQueue('q')->concurrency(2);

        $limit = $this->registry->all()[0];
        $this->assertSame(120, $limit->concurrency->slotTtlSeconds);
    }

    public function test_repeated_builder_calls_replace_prior_registration_for_same_target(): void
    {
        $b = $this->builderForQueue('q');
        $b->throttle(perMinute: 1);
        $b->throttle(perMinute: 5);

        $limits = $this->registry->all();
        $this->assertCount(1, $limits);
        $this->assertSame(5, $limits[0]->throttle->max);
    }
}
