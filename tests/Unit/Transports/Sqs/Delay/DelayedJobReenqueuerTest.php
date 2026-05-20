<?php

namespace Admnio\Sunset\Tests\Unit\Transports\Sqs\Delay;

use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Queue\Queue;
use Admnio\Sunset\Transports\Sqs\Delay\DelayedJobReenqueuer;
use Admnio\Sunset\Transports\Sqs\Delay\DelayedJobStore;
use Admnio\Sunset\Tests\TestCase;
use Mockery;
use Psr\Log\LoggerInterface;

class DelayedJobReenqueuerTest extends TestCase
{
    public function test_sweeps_due_jobs_back_to_their_source_connection(): void
    {
        $now = 1_700_000_100;

        $store = Mockery::mock(DelayedJobStore::class);
        $store->shouldReceive('due')->with($now + 60)->andReturn([
            [
                'member' => 'orders|sqs|n1|{"id":"a"}',
                'queue' => 'orders',
                'connection' => 'sqs',
                'payload' => '{"id":"a"}',
                'eta' => $now + 10.0,
            ],
            [
                'member' => 'default|rabbitmq|n2|{"id":"b"}',
                'queue' => 'default',
                'connection' => 'rabbitmq',
                'payload' => '{"id":"b"}',
                'eta' => $now + 50.0,
            ],
        ]);
        $store->shouldReceive('remove')->with('orders|sqs|n1|{"id":"a"}')->once();
        $store->shouldReceive('remove')->with('default|rabbitmq|n2|{"id":"b"}')->once();

        $sqsQueue = Mockery::mock(Queue::class);
        $sqsQueue->shouldReceive('pushRaw')
            ->with('{"id":"a"}', 'orders', Mockery::on(fn ($opts) => $opts['delay'] === 10))
            ->once();

        $rabbitQueue = Mockery::mock(Queue::class);
        $rabbitQueue->shouldReceive('pushRaw')
            ->with('{"id":"b"}', 'default', Mockery::on(fn ($opts) => $opts['delay'] === 50))
            ->once();

        $queues = Mockery::mock(QueueFactory::class);
        $queues->shouldReceive('connection')->with('sqs')->andReturn($sqsQueue);
        $queues->shouldReceive('connection')->with('rabbitmq')->andReturn($rabbitQueue);

        $logger = Mockery::spy(LoggerInterface::class);

        $reenqueuer = new DelayedJobReenqueuer($store, $queues, $logger, 60);
        $reenqueuer->sweep($now);

        $this->addToAssertionCount(Mockery::getContainer()->mockery_getExpectationCount());
    }

    public function test_partial_failure_leaves_entry(): void
    {
        $now = 1_700_000_100;

        $store = Mockery::mock(DelayedJobStore::class);
        $store->shouldReceive('due')->andReturn([
            [
                'member' => 'orders|sqs|n1|{"id":"a"}',
                'queue' => 'orders',
                'connection' => 'sqs',
                'payload' => '{"id":"a"}',
                'eta' => $now + 10.0,
            ],
            [
                'member' => 'orders|sqs|n2|{"id":"b"}',
                'queue' => 'orders',
                'connection' => 'sqs',
                'payload' => '{"id":"b"}',
                'eta' => $now + 20.0,
            ],
        ]);
        $store->shouldReceive('remove')->with('orders|sqs|n1|{"id":"a"}')->once();
        $store->shouldNotReceive('remove')->with('orders|sqs|n2|{"id":"b"}');

        $sqsQueue = Mockery::mock(Queue::class);
        $sqsQueue->shouldReceive('pushRaw')->with('{"id":"a"}', 'orders', Mockery::any())->once();
        $sqsQueue->shouldReceive('pushRaw')->with('{"id":"b"}', 'orders', Mockery::any())
            ->andThrow(new \RuntimeException('sqs failed'));

        $queues = Mockery::mock(QueueFactory::class);
        $queues->shouldReceive('connection')->with('sqs')->andReturn($sqsQueue);

        $logger = Mockery::spy(LoggerInterface::class);

        $reenqueuer = new DelayedJobReenqueuer($store, $queues, $logger, 60);
        $reenqueuer->sweep($now);

        $logger->shouldHaveReceived('warning')->once();

        // shouldHaveReceived and Mockery's shouldReceive expectations don't
        // register with PHPUnit; account for them explicitly.
        $this->addToAssertionCount(Mockery::getContainer()->mockery_getExpectationCount() + 1);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
