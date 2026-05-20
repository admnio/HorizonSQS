<?php

namespace Admnio\Sunset\Tests\Unit\Transports\Sqs\Delay;

use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\Connection as RedisConnection;
use Admnio\Sunset\Transports\Sqs\Delay\DelayedJobStore;
use Admnio\Sunset\Tests\TestCase;
use Mockery;

class DelayedJobStoreTest extends TestCase
{
    public function test_buffer_adds_to_sorted_set(): void
    {
        $conn = Mockery::mock(RedisConnection::class);
        $conn->shouldReceive('zadd')
            ->once()
            ->with('sunset:delayed', 1_700_000_000, Mockery::pattern('/^orders\\|sqs\\|[a-f0-9]+\\|\\{"id":"abc"\\}$/'))
            ->andReturn(1);

        $factory = Mockery::mock(RedisFactory::class);
        $factory->shouldReceive('connection')->with('default')->andReturn($conn);

        $store = new DelayedJobStore($factory, 'default');
        $store->buffer('orders', 'sqs', '{"id":"abc"}', 1_700_000_000);

        $this->addToAssertionCount(Mockery::getContainer()->mockery_getExpectationCount());
    }

    public function test_buffer_encodes_connection_segment(): void
    {
        $conn = Mockery::mock(RedisConnection::class);
        // Encoding must place the connection in segment 2: queue|connection|nonce|payload.
        $conn->shouldReceive('zadd')
            ->once()
            ->with('sunset:delayed', 1_700_000_000, Mockery::pattern('/^orders\\|rabbitmq\\|[a-f0-9]+\\|\\{"id":"abc"\\}$/'))
            ->andReturn(1);

        $factory = Mockery::mock(RedisFactory::class);
        $factory->shouldReceive('connection')->with('default')->andReturn($conn);

        $store = new DelayedJobStore($factory, 'default');
        $store->buffer('orders', 'rabbitmq', '{"id":"abc"}', 1_700_000_000);

        $this->addToAssertionCount(Mockery::getContainer()->mockery_getExpectationCount());
    }

    public function test_due_returns_entries_below_threshold_with_connection(): void
    {
        $conn = Mockery::mock(RedisConnection::class);
        $conn->shouldReceive('zrangebyscore')
            ->once()
            ->with('sunset:delayed', '-inf', 1_700_000_060, ['withscores' => true])
            ->andReturn([
                'orders|rabbitmq|nonce1|{"id":"a"}' => 1_700_000_010,
                'orders|sqs|nonce2|{"id":"b"}' => 1_700_000_050,
            ]);

        $factory = Mockery::mock(RedisFactory::class);
        $factory->shouldReceive('connection')->with('default')->andReturn($conn);

        $store = new DelayedJobStore($factory, 'default');
        $entries = $store->due(1_700_000_060);

        $this->assertCount(2, $entries);
        $this->assertSame('orders', $entries[0]['queue']);
        $this->assertSame('rabbitmq', $entries[0]['connection']);
        $this->assertSame('{"id":"a"}', $entries[0]['payload']);
        $this->assertSame(1_700_000_010.0, $entries[0]['eta']);

        $this->assertSame('sqs', $entries[1]['connection']);
        $this->assertSame('{"id":"b"}', $entries[1]['payload']);
    }

    public function test_due_parses_legacy_three_segment_entries_as_sqs(): void
    {
        // Backwards-compat: pre-v0.6.0 SQS-only entries used `queue|nonce|payload`.
        // They must still surface from due() with connection='sqs' so in-flight
        // delayed jobs aren't lost on upgrade.
        $conn = Mockery::mock(RedisConnection::class);
        $conn->shouldReceive('zrangebyscore')
            ->once()
            ->with('sunset:delayed', '-inf', 1_700_000_060, ['withscores' => true])
            ->andReturn([
                'orders|legacyNonce|{"id":"legacy"}' => 1_700_000_005,
            ]);

        $factory = Mockery::mock(RedisFactory::class);
        $factory->shouldReceive('connection')->with('default')->andReturn($conn);

        $store = new DelayedJobStore($factory, 'default');
        $entries = $store->due(1_700_000_060);

        $this->assertCount(1, $entries);
        $this->assertSame('orders', $entries[0]['queue']);
        $this->assertSame('sqs', $entries[0]['connection'], 'Legacy 3-segment members must default to sqs');
        $this->assertSame('{"id":"legacy"}', $entries[0]['payload']);
    }

    public function test_remove_zrems_by_member(): void
    {
        $conn = Mockery::mock(RedisConnection::class);
        $conn->shouldReceive('zrem')
            ->once()
            ->with('sunset:delayed', 'orders|sqs|nonce|{"id":"a"}')
            ->andReturn(1);

        $factory = Mockery::mock(RedisFactory::class);
        $factory->shouldReceive('connection')->with('default')->andReturn($conn);

        $store = new DelayedJobStore($factory, 'default');
        $store->remove('orders|sqs|nonce|{"id":"a"}');

        $this->addToAssertionCount(Mockery::getContainer()->mockery_getExpectationCount());
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
