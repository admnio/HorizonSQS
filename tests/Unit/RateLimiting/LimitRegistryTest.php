<?php

namespace Admnio\Sunset\Tests\Unit\RateLimiting;

use Admnio\Sunset\RateLimiting\Limit;
use Admnio\Sunset\RateLimiting\LimitRegistry;
use Admnio\Sunset\RateLimiting\Targets\JobClassTarget;
use Admnio\Sunset\RateLimiting\Targets\QueueTarget;
use Admnio\Sunset\RateLimiting\ThrottleSpec;
use Admnio\Sunset\Tests\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;

class LimitRegistryTest extends TestCase
{
    public function test_empty_registry_returns_no_matches_and_is_empty(): void
    {
        $registry = new LimitRegistry();

        $this->assertTrue($registry->isEmpty());
        $this->assertSame([], $registry->resolve(null, [], 'q', []));
        $this->assertSame([], $registry->all());
    }

    public function test_resolve_returns_limits_for_matching_queue(): void
    {
        $registry = new LimitRegistry();
        $limit = new Limit(
            name: 'queue:geocode',
            target: new QueueTarget('geocode'),
            throttle: new ThrottleSpec(10, 60),
        );
        $registry->upsert($limit);

        $matches = $registry->resolve(null, [], 'geocode', []);

        $this->assertCount(1, $matches);
        $this->assertSame($limit, $matches[0]);
        $this->assertFalse($registry->isEmpty());
    }

    public function test_resolve_returns_nothing_for_non_matching_queue(): void
    {
        $registry = new LimitRegistry();
        $registry->upsert(new Limit(
            name: 'queue:geocode',
            target: new QueueTarget('geocode'),
            throttle: new ThrottleSpec(10, 60),
        ));

        $matches = $registry->resolve(null, [], 'other', []);

        $this->assertSame([], $matches);
    }

    public function test_resolve_returns_limits_for_matching_job_class_via_command_name(): void
    {
        $registry = new LimitRegistry();
        $limit = new Limit(
            name: 'class:App\\Jobs\\Geo',
            target: new JobClassTarget('App\\Jobs\\Geo'),
            throttle: new ThrottleSpec(5, 60),
        );
        $registry->upsert($limit);

        $payload = ['data' => ['commandName' => 'App\\Jobs\\Geo']];
        $matches = $registry->resolve(null, $payload, 'unrelated-queue', []);

        $this->assertCount(1, $matches);
        $this->assertSame($limit, $matches[0]);
    }

    public function test_condition_closure_filters_matches(): void
    {
        $registry = new LimitRegistry();
        $included = new Limit(
            name: 'queue:q-yes',
            target: new QueueTarget('q'),
            throttle: new ThrottleSpec(1, 1),
            condition: fn () => true,
        );
        $excluded = new Limit(
            name: 'queue:q-no',
            target: new QueueTarget('q'),
            throttle: new ThrottleSpec(1, 1),
            condition: fn () => false,
        );
        $registry->upsert($included);
        $registry->upsert($excluded);

        $matches = $registry->resolve(null, [], 'q', []);

        $this->assertCount(1, $matches);
        $this->assertSame($included, $matches[0]);
    }

    public function test_condition_throwing_is_treated_as_not_applying_and_logged(): void
    {
        $logger = new class extends AbstractLogger {
            public array $records = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message];
            }
        };

        $registry = new LimitRegistry($logger);
        $registry->upsert(new Limit(
            name: 'queue:q',
            target: new QueueTarget('q'),
            throttle: new ThrottleSpec(1, 1),
            condition: function () {
                throw new RuntimeException('boom');
            },
        ));

        $matches = $registry->resolve(null, [], 'q', []);

        $this->assertSame([], $matches);
        $this->assertNotEmpty($logger->records);
        $this->assertStringContainsString('boom', $logger->records[0]['message']);
    }

    public function test_upsert_replaces_existing_limit_with_same_name(): void
    {
        $registry = new LimitRegistry();
        $registry->upsert(new Limit(
            name: 'queue:q',
            target: new QueueTarget('q'),
            throttle: new ThrottleSpec(1, 60),
        ));
        $replacement = new Limit(
            name: 'queue:q',
            target: new QueueTarget('q'),
            throttle: new ThrottleSpec(99, 60),
        );
        $registry->upsert($replacement);

        $matches = $registry->resolve(null, [], 'q', []);

        $this->assertCount(1, $matches);
        $this->assertSame($replacement, $matches[0]);
        $this->assertCount(1, $registry->all());
    }

    public function test_upsert_with_renamed_target_does_not_leave_stale_entries(): void
    {
        $registry = new LimitRegistry();
        // First register against queue "a"
        $registry->upsert(new Limit(
            name: 'limit-x',
            target: new QueueTarget('a'),
            throttle: new ThrottleSpec(1, 60),
        ));
        // Now upsert the same name targeting queue "b"
        $registry->upsert(new Limit(
            name: 'limit-x',
            target: new QueueTarget('b'),
            throttle: new ThrottleSpec(1, 60),
        ));

        $this->assertSame([], $registry->resolve(null, [], 'a', []));
        $this->assertCount(1, $registry->resolve(null, [], 'b', []));
    }
}
