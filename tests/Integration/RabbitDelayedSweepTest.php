<?php

namespace Admnio\Sunset\Tests\Integration;

use Admnio\Sunset\Support\TransportRegistry;
use Admnio\Sunset\Tests\Fixtures\Jobs\RecordingJob;
use Admnio\Sunset\Transports\Rabbit\RabbitTransport;
use Admnio\Sunset\Transports\Sqs\Delay\DelayedJobReenqueuer;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Facades\Queue;
use PhpAmqpLib\Connection\AMQPStreamConnection;

/**
 * Proves the end-to-end delayed-sweep loop on the rabbitmq connection:
 *
 *   later() → DelayedJobStore.buffer() → sweep() → RabbitQueue.pushRaw() → pop+fire
 *
 * Pre-v0.6.0 the reaper had a hardcoded SQS destination, so a job buffered
 * by RabbitQueue::later() would be reaped onto SQS rather than back to
 * RabbitMQ. This test pins that the per-entry source-connection routing
 * lands the swept job on the originating transport.
 */
class RabbitDelayedSweepTest extends IntegrationTestCase
{
    private const TEST_QUEUE = 'sunset-rabbit-sweep-test';
    private const DELAYED_KEY = 'sunset:delayed';

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureRabbitMQAvailable();

        config([
            'queue.default' => 'rabbitmq',
            'queue.connections.rabbitmq.queue' => self::TEST_QUEUE,
        ]);

        $this->purgeTestQueue();
        $this->purgeDelayedStore();
        @unlink(sys_get_temp_dir() . '/sunset-marker');
    }

    protected function tearDown(): void
    {
        // Defensive cleanup so a failure here doesn't poison the next run.
        try {
            $this->purgeTestQueue();
        } catch (\Throwable $e) {
            // RabbitMQ may already be down at teardown time.
        }

        try {
            $this->purgeDelayedStore();
        } catch (\Throwable $e) {
            // Same idea for Redis.
        }

        @unlink(sys_get_temp_dir() . '/sunset-marker');

        parent::tearDown();
    }

    public function test_delayed_rabbit_job_is_swept_back_into_rabbit_not_sqs(): void
    {
        // 1. Push a short-delay job via the rabbitmq connection. The 2-second
        //    delay is just long enough for the buffer write to be observable
        //    before the eta passes.
        Queue::connection('rabbitmq')->later(2, new RecordingJob('sweep-marker'));

        /** @var RabbitTransport $transport */
        $transport = $this->app->make(TransportRegistry::class)->get('rabbitmq');

        // At buffer time, nothing should be on the AMQP queue yet — the job
        // lives in the DelayedJobStore Redis ZSET.
        $this->assertSame(
            0,
            $transport->workload([self::TEST_QUEUE])[0]['length'],
            'Buffered job must not be on RabbitMQ before sweep'
        );

        // 2. Wait for the eta to pass.
        sleep(3);

        // 3. Sweep directly (don't depend on the schedule loop running).
        /** @var DelayedJobReenqueuer $reenqueuer */
        $reenqueuer = $this->app->make(DelayedJobReenqueuer::class);
        $reenqueuer->sweep();

        // 4. The job must now be on the RabbitMQ queue, NOT on SQS. This is
        //    the regression-pin: pre-v0.6.0 the reaper hardcoded the 'sqs'
        //    connection and would publish a Rabbit-sourced delayed job to
        //    SQS instead, silently losing it from the originating transport.
        $workload = $transport->workload([self::TEST_QUEUE]);
        $this->assertSame(self::TEST_QUEUE, $workload[0]['name']);
        $this->assertGreaterThanOrEqual(
            1,
            $workload[0]['length'],
            'Swept job must be republished back to RabbitMQ on queue ' . self::TEST_QUEUE
        );

        // 5. Full round-trip: pop + fire and verify the marker file. This
        //    proves the swept payload is still valid and runnable.
        $job = Queue::connection('rabbitmq')->pop(self::TEST_QUEUE);
        $this->assertNotNull($job, 'Expected to pop the swept job from ' . self::TEST_QUEUE);

        $job->fire();

        $this->assertSame(
            'sweep-marker',
            file_get_contents(sys_get_temp_dir() . '/sunset-marker'),
            'Swept job must execute and write its marker'
        );
    }

    /**
     * Declare, bind, and purge the test queue. Mirrors RabbitDelayedTest's
     * helper — copied locally so each integration test owns its cleanup and
     * can run independently. amq.direct is configured in TestCase; we bind
     * with a routing key matching the queue name.
     */
    private function purgeTestQueue(): void
    {
        $connection = new AMQPStreamConnection(
            env('RABBITMQ_HOST', '127.0.0.1'),
            (int) env('RABBITMQ_PORT', 5672),
            env('RABBITMQ_USER', 'guest'),
            env('RABBITMQ_PASSWORD', 'guest'),
            env('RABBITMQ_VHOST', '/'),
        );

        try {
            $channel = $connection->channel();
            try {
                $channel->queue_declare(self::TEST_QUEUE, false, true, false, false);
                $channel->queue_bind(self::TEST_QUEUE, 'amq.direct', self::TEST_QUEUE);
                $channel->queue_purge(self::TEST_QUEUE);
            } finally {
                $channel->close();
            }
        } finally {
            $connection->close();
        }
    }

    /**
     * Wipe the Redis ZSET that backs DelayedJobStore. We delete the whole
     * key because the test owns its Redis database (database 1) and no
     * other test data overlaps.
     */
    private function purgeDelayedStore(): void
    {
        /** @var RedisFactory $factory */
        $factory = $this->app->make(RedisFactory::class);
        $factory->connection('default')->del(self::DELAYED_KEY);
    }
}
