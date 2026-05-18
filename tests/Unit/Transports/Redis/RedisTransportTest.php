<?php

namespace Admnio\Sunset\Tests\Unit\Transports\Redis;

use Admnio\Sunset\Transports\Redis\RedisQueue;
use Admnio\Sunset\Transports\Redis\RedisTransport;
use Admnio\Sunset\Tests\TestCase;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\Connection as RedisConnection;
use Mockery;
use Psr\Log\LoggerInterface;

class RedisTransportTest extends TestCase
{
    public function test_name_returns_redis(): void
    {
        $transport = $this->makeTransport();
        $this->assertSame('redis', $transport->name());
    }

    public function test_connect_returns_redis_queue(): void
    {
        $transport = $this->makeTransport();

        $queue = $transport->connect([
            'queue' => 'default',
            'connection' => 'default',
            'retry_after' => 60,
            'block_for' => null,
        ]);

        $this->assertInstanceOf(RedisQueue::class, $queue);
    }

    public function test_workload_aggregates_per_queue_depth(): void
    {
        $conn = Mockery::mock(RedisConnection::class);
        $conn->shouldReceive('llen')->with('queues:orders')->andReturn(40);
        $conn->shouldReceive('zcard')->with('queues:orders:delayed')->andReturn(5);
        $conn->shouldReceive('zcard')->with('queues:orders:reserved')->andReturn(2);
        $conn->shouldReceive('llen')->with('queues:default')->andReturn(10);
        $conn->shouldReceive('zcard')->with('queues:default:delayed')->andReturn(0);
        $conn->shouldReceive('zcard')->with('queues:default:reserved')->andReturn(1);

        $factory = Mockery::mock(RedisFactory::class);
        $factory->shouldReceive('connection')->with('default')->andReturn($conn);

        $transport = new RedisTransport(
            redis: $factory,
            packageConfig: config('sunset'),
            logger: null,
        );

        $workload = $transport->workload(['orders', 'default']);
        $byName = collect($workload)->keyBy('name')->all();

        $this->assertSame(47, $byName['orders']['length']); // 40 + 5 + 2
        $this->assertSame(11, $byName['default']['length']); // 10 + 0 + 1
    }

    public function test_workload_logs_and_continues_on_failure(): void
    {
        $conn = Mockery::mock(RedisConnection::class);
        $conn->shouldReceive('llen')->with('queues:broken')->andThrow(new \RuntimeException('boom'));
        $conn->shouldReceive('llen')->with('queues:ok')->andReturn(5);
        $conn->shouldReceive('zcard')->with('queues:ok:delayed')->andReturn(0);
        $conn->shouldReceive('zcard')->with('queues:ok:reserved')->andReturn(0);

        $factory = Mockery::mock(RedisFactory::class);
        $factory->shouldReceive('connection')->with('default')->andReturn($conn);

        $logger = Mockery::spy(LoggerInterface::class);

        $transport = new RedisTransport(
            redis: $factory,
            packageConfig: config('sunset'),
            logger: $logger,
        );

        $workload = $transport->workload(['broken', 'ok']);
        $byName = collect($workload)->keyBy('name')->all();

        $this->assertSame(0, $byName['broken']['length']);
        $this->assertSame(5, $byName['ok']['length']);

        $logger->shouldHaveReceived('warning')->once();
    }

    private function makeTransport(): RedisTransport
    {
        return new RedisTransport(
            redis: $this->app->make(RedisFactory::class),
            packageConfig: config('sunset'),
            logger: null,
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
