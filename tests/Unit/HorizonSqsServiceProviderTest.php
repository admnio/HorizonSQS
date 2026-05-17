<?php

namespace MasonWorkforce\HorizonSqs\Tests\Unit;

use MasonWorkforce\HorizonSqs\Tests\TestCase;

class HorizonSqsServiceProviderTest extends TestCase
{
    public function test_publishes_config(): void
    {
        $this->assertSame('default', config('horizon-sqs.redis_connection'));
        $this->assertSame(5, config('horizon-sqs.workload_cache_ttl'));
    }

    public function test_workload_repository_binding_swapped(): void
    {
        $resolved = $this->app->make(\Laravel\Horizon\Contracts\WorkloadRepository::class);

        $this->assertInstanceOf(
            \MasonWorkforce\HorizonSqs\Repositories\SqsWorkloadRepository::class,
            $resolved
        );
    }

    public function test_sqs_driver_resolves_to_horizon_sqs_queue(): void
    {
        $queue = $this->app['queue']->connection('sqs');

        $this->assertInstanceOf(
            \MasonWorkforce\HorizonSqs\Queue\HorizonSqsQueue::class,
            $queue
        );
    }

    public function test_artisan_command_registered(): void
    {
        $commands = array_keys($this->app->make(\Illuminate\Contracts\Console\Kernel::class)->all());
        $this->assertContains('horizon-sqs:sweep-delayed', $commands);
    }

    public function test_validates_redis_connection_config(): void
    {
        config(['horizon-sqs.redis_connection' => 'nonexistent-connection']);
        config(['database.redis.nonexistent-connection' => null]);

        $this->expectException(\MasonWorkforce\HorizonSqs\Exceptions\InvalidConfigurationException::class);
        $this->app->make(\MasonWorkforce\HorizonSqs\Queue\HorizonSqsConnector::class)
            ->connect(config('queue.connections.sqs'));
    }
}
