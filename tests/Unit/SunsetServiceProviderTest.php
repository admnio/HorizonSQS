<?php

namespace Admnio\Sunset\Tests\Unit;

use Admnio\Sunset\Tests\TestCase;

class SunsetServiceProviderTest extends TestCase
{
    public function test_publishes_config(): void
    {
        $this->assertSame('default', config('sunset.redis_connection'));
        $this->assertSame(5, config('sunset.workload_cache_ttl'));
    }

    public function test_workload_repository_binding_swapped(): void
    {
        $resolved = $this->app->make(\Laravel\Horizon\Contracts\WorkloadRepository::class);

        $this->assertInstanceOf(
            \Admnio\Sunset\Repositories\SunsetWorkloadRepository::class,
            $resolved
        );
    }

    public function test_sqs_driver_resolves_to_sqs_queue(): void
    {
        $queue = $this->app['queue']->connection('sqs');

        $this->assertInstanceOf(
            \Admnio\Sunset\Transports\Sqs\SqsQueue::class,
            $queue
        );
    }

    public function test_redis_driver_resolves_to_sunset_redis_queue(): void
    {
        config([
            'queue.connections.redis' => [
                'driver' => 'redis',
                'connection' => 'default',
                'queue' => 'default',
                'retry_after' => 60,
            ],
        ]);

        $queue = $this->app['queue']->connection('redis');

        $this->assertInstanceOf(
            \Admnio\Sunset\Transports\Redis\RedisQueue::class,
            $queue
        );
    }

    public function test_artisan_command_registered(): void
    {
        $commands = array_keys($this->app->make(\Illuminate\Contracts\Console\Kernel::class)->all());
        $this->assertContains('sunset:sweep-delayed', $commands);
    }

    public function test_validates_redis_connection_config(): void
    {
        config(['sunset.redis_connection' => 'nonexistent-connection']);
        config(['database.redis.nonexistent-connection' => null]);

        $this->expectException(\Admnio\Sunset\Exceptions\InvalidConfigurationException::class);
        $this->app->make(\Admnio\Sunset\Transports\Sqs\SqsConnector::class)
            ->connect(config('queue.connections.sqs'));
    }

    public function test_sunset_contracts_resolve_to_redis_implementations(): void
    {
        $this->assertInstanceOf(
            \Admnio\Sunset\Repositories\Redis\RedisJobRepository::class,
            $this->app->make(\Admnio\Sunset\Contracts\JobRepository::class)
        );
        $this->assertInstanceOf(
            \Admnio\Sunset\Repositories\Redis\RedisFailedJobRepository::class,
            $this->app->make(\Admnio\Sunset\Contracts\FailedJobRepository::class)
        );
        $this->assertInstanceOf(
            \Admnio\Sunset\Repositories\Redis\RedisTagRepository::class,
            $this->app->make(\Admnio\Sunset\Contracts\TagRepository::class)
        );
        $this->assertInstanceOf(
            \Admnio\Sunset\Repositories\Redis\RedisMetricsRepository::class,
            $this->app->make(\Admnio\Sunset\Contracts\MetricsRepository::class)
        );
    }

    public function test_horizon_repository_contracts_resolve_to_sunset_adapters(): void
    {
        $this->assertInstanceOf(
            \Admnio\Sunset\Adapters\Horizon\HorizonJobRepositoryAdapter::class,
            $this->app->make(\Laravel\Horizon\Contracts\JobRepository::class)
        );
        $this->assertInstanceOf(
            \Admnio\Sunset\Adapters\Horizon\HorizonTagRepositoryAdapter::class,
            $this->app->make(\Laravel\Horizon\Contracts\TagRepository::class)
        );
        $this->assertInstanceOf(
            \Admnio\Sunset\Adapters\Horizon\HorizonMetricsRepositoryAdapter::class,
            $this->app->make(\Laravel\Horizon\Contracts\MetricsRepository::class)
        );
    }

    public function test_horizon_listeners_are_not_registered_for_sunset_events(): void
    {
        $events = $this->app['events'];
        foreach ([
            \Admnio\Sunset\Events\JobQueueing::class,
            \Admnio\Sunset\Events\JobQueued::class,
            \Admnio\Sunset\Events\JobReserved::class,
            \Admnio\Sunset\Events\JobReleased::class,
            \Admnio\Sunset\Events\JobCompleted::class,
            \Admnio\Sunset\Events\JobFailed::class,
        ] as $eventClass) {
            foreach ($events->getListeners($eventClass) as $listener) {
                $repr = is_object($listener) ? get_class($listener) : (string) $listener;
                $this->assertStringNotContainsString(
                    'Laravel\\Horizon\\Listeners',
                    $repr,
                    "Sunset event {$eventClass} should not have Horizon listeners"
                );
            }
        }
    }

    public function test_sunset_listeners_are_registered_for_each_sunset_event(): void
    {
        $events = $this->app['events'];
        $expected = [
            \Admnio\Sunset\Events\JobQueueing::class => 2,
            \Admnio\Sunset\Events\JobQueued::class => 1,
            \Admnio\Sunset\Events\JobReserved::class => 1,
            \Admnio\Sunset\Events\JobReleased::class => 1,
            \Admnio\Sunset\Events\JobCompleted::class => 1,
            \Admnio\Sunset\Events\JobFailed::class => 1,
        ];
        foreach ($expected as $eventClass => $minListeners) {
            $count = count($events->getListeners($eventClass));
            $this->assertGreaterThanOrEqual($minListeners, $count,
                "{$eventClass} should have at least {$minListeners} listener(s), has {$count}");
        }
    }

    public function test_metrics_snapshot_is_scheduled_every_five_minutes(): void
    {
        $schedule = $this->app->make(\Illuminate\Console\Scheduling\Schedule::class);
        $events = collect($schedule->events());
        $snapshot = $events->first(fn ($e) => $e->description === 'sunset-snapshot');
        $this->assertNotNull($snapshot, 'sunset-snapshot should be scheduled');
        $this->assertSame('*/5 * * * *', $snapshot->expression);
    }
}
