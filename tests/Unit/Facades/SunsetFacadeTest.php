<?php

namespace Admnio\Sunset\Tests\Unit\Facades;

use Admnio\Sunset\Facades\Sunset;
use Admnio\Sunset\Manager;
use Admnio\Sunset\RateLimiting\LimitBuilder;
use Admnio\Sunset\RateLimiting\LimitRegistry;
use Admnio\Sunset\Tests\TestCase;

class SunsetFacadeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Force a single shared LimitRegistry instance per test so the registry
        // the Manager writes to is the same one we read from below. B6 will
        // bind LimitRegistry as a singleton in the service provider; until then,
        // each $container->make(LimitRegistry::class) returns a fresh instance.
        $this->app->instance(LimitRegistry::class, new LimitRegistry());
    }

    public function test_facade_resolves_to_manager_singleton(): void
    {
        $root = Sunset::getFacadeRoot();
        $this->assertInstanceOf(Manager::class, $root);
        $this->assertSame($this->app->make(Manager::class), $root);
    }

    public function test_for_returns_limit_builder_targeting_a_queue(): void
    {
        $builder = Sunset::for('orders');
        $this->assertInstanceOf(LimitBuilder::class, $builder);
    }

    public function test_limit_returns_limit_builder_targeting_a_job_class(): void
    {
        $builder = Sunset::limit('App\\Jobs\\Geocode');
        $this->assertInstanceOf(LimitBuilder::class, $builder);
    }

    public function test_chained_throttle_registers_a_limit(): void
    {
        Sunset::for('queue-x')->throttle(perMinute: 5);

        $registry = $this->app->make(LimitRegistry::class);
        $limits = $registry->resolve(null, [], 'queue-x', []);

        $this->assertCount(1, $limits);
        $this->assertSame(5, $limits[0]->throttle->max);
        $this->assertSame(60, $limits[0]->throttle->windowSeconds);
    }

    public function test_limit_targeting_a_job_class_matches_via_payload_commandName(): void
    {
        Sunset::limit('App\\Jobs\\GeocodeAddress')->throttle(perHour: 100);

        $registry = $this->app->make(LimitRegistry::class);
        $matches = $registry->resolve(
            null,
            ['data' => ['commandName' => 'App\\Jobs\\GeocodeAddress']],
            'default',
            []
        );

        $this->assertCount(1, $matches);
        $this->assertSame(100, $matches[0]->throttle->max);
    }
}
