<?php

namespace Admnio\Sunset\Tests\Unit\Transports\Redis;

use Admnio\Sunset\Transports\Redis\RedisQueue;
use Admnio\Sunset\Tests\TestCase;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Admnio\Sunset\Events\JobQueueing;
use Admnio\Sunset\Events\JobQueued;
use Admnio\Sunset\Events\JobReserved;
use Illuminate\Support\Facades\Event;

class RedisQueueTest extends TestCase
{
    public function test_push_raw_dispatches_sunset_events_around_send(): void
    {
        Event::fake([JobQueueing::class, JobQueued::class]);

        $factory = $this->app->make(RedisFactory::class);
        $queue = new RedisQueue($factory, 'default', 'default');
        $queue->setContainer($this->app);
        $queue->setConnectionName('redis');

        $queueName = 'rq-test-' . uniqid();
        try {
            $queue->pushRaw(json_encode(['id' => 'abc', 'displayName' => 'TestJob', 'data' => []]), $queueName);

            Event::assertDispatched(JobQueueing::class, function ($e) {
                return $e->connectionName === 'redis';
            });
            Event::assertDispatched(JobQueued::class, function ($e) {
                return $e->connectionName === 'redis';
            });
        } finally {
            $factory->connection('default')->del("queues:{$queueName}");
        }
    }

    public function test_pop_dispatches_job_reserved(): void
    {
        Event::fake([JobReserved::class]);

        $factory = $this->app->make(RedisFactory::class);
        $queue = new RedisQueue($factory, 'default', 'default');
        $queue->setContainer($this->app);
        $queue->setConnectionName('redis');

        $queueName = 'rq-test-pop-' . uniqid();
        try {
            $factory->connection('default')->rpush(
                "queues:{$queueName}",
                json_encode(['id' => 'abc', 'displayName' => 'TestJob', 'data' => [], 'attempts' => 0])
            );

            $job = $queue->pop($queueName);
            $this->assertNotNull($job);

            Event::assertDispatched(JobReserved::class);
        } finally {
            $factory->connection('default')->del("queues:{$queueName}");
            $factory->connection('default')->del("queues:{$queueName}:reserved");
        }
    }
}
