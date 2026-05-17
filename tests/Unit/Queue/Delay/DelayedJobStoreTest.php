<?php

namespace MasonWorkforce\HorizonSqs\Tests\Unit\Queue\Delay;

use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\Connection as RedisConnection;
use MasonWorkforce\HorizonSqs\Queue\Delay\DelayedJobStore;
use MasonWorkforce\HorizonSqs\Tests\TestCase;
use Mockery;

class DelayedJobStoreTest extends TestCase
{
    public function test_buffer_adds_to_sorted_set(): void
    {
        $conn = Mockery::mock(RedisConnection::class);
        $conn->shouldReceive('zadd')
            ->once()
            ->with('horizon-sqs:delayed', 1_700_000_000, Mockery::pattern('/^orders\\|.*\\|\\{"id":"abc"\\}$/'))
            ->andReturn(1);

        $factory = Mockery::mock(RedisFactory::class);
        $factory->shouldReceive('connection')->with('default')->andReturn($conn);

        $store = new DelayedJobStore($factory, 'default');
        $store->buffer('orders', '{"id":"abc"}', 1_700_000_000);
    }

    public function test_due_returns_entries_below_threshold(): void
    {
        $conn = Mockery::mock(RedisConnection::class);
        $conn->shouldReceive('zrangebyscore')
            ->once()
            ->with('horizon-sqs:delayed', '-inf', 1_700_000_060, ['withscores' => true])
            ->andReturn([
                'orders|nonce1|{"id":"a"}' => 1_700_000_010,
                'orders|nonce2|{"id":"b"}' => 1_700_000_050,
            ]);

        $factory = Mockery::mock(RedisFactory::class);
        $factory->shouldReceive('connection')->with('default')->andReturn($conn);

        $store = new DelayedJobStore($factory, 'default');
        $entries = $store->due(1_700_000_060);

        $this->assertCount(2, $entries);
        $this->assertSame('orders', $entries[0]['queue']);
        $this->assertSame('{"id":"a"}', $entries[0]['payload']);
        $this->assertSame(1_700_000_010.0, $entries[0]['eta']);
    }

    public function test_remove_zrems_by_member(): void
    {
        $conn = Mockery::mock(RedisConnection::class);
        $conn->shouldReceive('zrem')
            ->once()
            ->with('horizon-sqs:delayed', 'orders|nonce|{"id":"a"}')
            ->andReturn(1);

        $factory = Mockery::mock(RedisFactory::class);
        $factory->shouldReceive('connection')->with('default')->andReturn($conn);

        $store = new DelayedJobStore($factory, 'default');
        $store->remove('orders|nonce|{"id":"a"}');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
