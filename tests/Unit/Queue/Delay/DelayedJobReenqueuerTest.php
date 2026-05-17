<?php

namespace MasonWorkforce\HorizonSqs\Tests\Unit\Queue\Delay;

use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Queue\Queue;
use MasonWorkforce\HorizonSqs\Queue\Delay\DelayedJobReenqueuer;
use MasonWorkforce\HorizonSqs\Queue\Delay\DelayedJobStore;
use MasonWorkforce\HorizonSqs\Tests\TestCase;
use Mockery;

class DelayedJobReenqueuerTest extends TestCase
{
    public function test_sweeps_due_jobs_to_sqs(): void
    {
        $now = 1_700_000_100;

        $store = Mockery::mock(DelayedJobStore::class);
        $store->shouldReceive('due')->with($now + 60)->andReturn([
            ['member' => 'orders|n1|{"id":"a"}', 'queue' => 'orders', 'payload' => '{"id":"a"}', 'eta' => $now + 10.0],
            ['member' => 'default|n2|{"id":"b"}', 'queue' => 'default', 'payload' => '{"id":"b"}', 'eta' => $now + 50.0],
        ]);
        $store->shouldReceive('remove')->with('orders|n1|{"id":"a"}')->once();
        $store->shouldReceive('remove')->with('default|n2|{"id":"b"}')->once();

        $sqsQueue = Mockery::mock(Queue::class);
        $sqsQueue->shouldReceive('pushRaw')
            ->with('{"id":"a"}', 'orders', Mockery::on(fn ($opts) => $opts['delay'] === 10))
            ->once();
        $sqsQueue->shouldReceive('pushRaw')
            ->with('{"id":"b"}', 'default', Mockery::on(fn ($opts) => $opts['delay'] === 50))
            ->once();

        $queues = Mockery::mock(QueueFactory::class);
        $queues->shouldReceive('connection')->with('sqs')->andReturn($sqsQueue);

        $reenqueuer = new DelayedJobReenqueuer($store, $queues, 'sqs', 60);
        $reenqueuer->sweep($now);
    }

    public function test_partial_failure_leaves_entry(): void
    {
        $now = 1_700_000_100;

        $store = Mockery::mock(DelayedJobStore::class);
        $store->shouldReceive('due')->andReturn([
            ['member' => 'orders|n1|{"id":"a"}', 'queue' => 'orders', 'payload' => '{"id":"a"}', 'eta' => $now + 10.0],
            ['member' => 'orders|n2|{"id":"b"}', 'queue' => 'orders', 'payload' => '{"id":"b"}', 'eta' => $now + 20.0],
        ]);
        $store->shouldReceive('remove')->with('orders|n1|{"id":"a"}')->once();
        $store->shouldNotReceive('remove')->with('orders|n2|{"id":"b"}');

        $sqsQueue = Mockery::mock(Queue::class);
        $sqsQueue->shouldReceive('pushRaw')->with('{"id":"a"}', 'orders', Mockery::any())->once();
        $sqsQueue->shouldReceive('pushRaw')->with('{"id":"b"}', 'orders', Mockery::any())
            ->andThrow(new \RuntimeException('sqs failed'));

        $queues = Mockery::mock(QueueFactory::class);
        $queues->shouldReceive('connection')->andReturn($sqsQueue);

        $reenqueuer = new DelayedJobReenqueuer($store, $queues, 'sqs', 60);
        $reenqueuer->sweep($now);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
