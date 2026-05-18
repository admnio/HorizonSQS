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
}
