<?php

namespace Admnio\Sunset\Tests\Integration;

use Admnio\Sunset\Events\JobQueued;
use Admnio\Sunset\Events\JobQueueing;
use Admnio\Sunset\Events\JobReserved;
use Admnio\Sunset\Support\TransportRegistry;
use Admnio\Sunset\Tests\Fixtures\Jobs\RecordingJob;
use Admnio\Sunset\Transports\Rabbit\RabbitTransport;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use PhpAmqpLib\Connection\AMQPStreamConnection;

class RabbitRoundtripTest extends IntegrationTestCase
{
    private const TEST_QUEUE = 'sunset-rabbit-test';

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureRabbitMQAvailable();

        // Route default-connection pushes to the rabbitmq driver on our test queue.
        config([
            'queue.default' => 'rabbitmq',
            'queue.connections.rabbitmq.queue' => self::TEST_QUEUE,
        ]);

        $this->purgeTestQueue();
        @unlink(sys_get_temp_dir() . '/sunset-marker');
    }

    protected function tearDown(): void
    {
        // Best-effort cleanup so the next test starts clean even if this one threw.
        try {
            $this->purgeTestQueue();
        } catch (\Throwable $e) {
            // Swallow — RabbitMQ may already be down at teardown time.
        }

        @unlink(sys_get_temp_dir() . '/sunset-marker');

        parent::tearDown();
    }

    public function test_push_pop_process_roundtrip_via_rabbitmq_driver(): void
    {
        Queue::push(new RecordingJob('hello-from-rabbit'));

        $job = Queue::connection('rabbitmq')->pop(self::TEST_QUEUE);
        $this->assertNotNull($job, 'Expected to pop a job from ' . self::TEST_QUEUE);

        $job->fire();

        $this->assertSame(
            'hello-from-rabbit',
            file_get_contents(sys_get_temp_dir() . '/sunset-marker')
        );
    }

    public function test_pushRaw_fires_JobQueueing_and_JobQueued_events(): void
    {
        Event::fake([JobQueueing::class, JobQueued::class]);

        Queue::push(new RecordingJob('events-test'));

        Event::assertDispatched(JobQueueing::class);
        Event::assertDispatched(JobQueued::class);
    }

    public function test_pop_fires_JobReserved(): void
    {
        Queue::push(new RecordingJob('reserved-test'));

        Event::fake([JobReserved::class]);

        $job = Queue::connection('rabbitmq')->pop(self::TEST_QUEUE);
        $this->assertNotNull($job, 'Expected to pop a job from ' . self::TEST_QUEUE);

        Event::assertDispatched(JobReserved::class);
    }

    public function test_workload_reports_queue_depth_for_real_rabbit_queue(): void
    {
        Queue::push(new RecordingJob('depth-1'));
        Queue::push(new RecordingJob('depth-2'));
        Queue::push(new RecordingJob('depth-3'));

        /** @var RabbitTransport $transport */
        $transport = $this->app->make(TransportRegistry::class)->get('rabbitmq');

        $workload = $transport->workload([self::TEST_QUEUE]);
        $byName = collect($workload)->keyBy('name')->all();

        $this->assertArrayHasKey(self::TEST_QUEUE, $byName);
        $this->assertGreaterThanOrEqual(
            3,
            $byName[self::TEST_QUEUE]['length'],
            'Expected workload length to be at least 3 after pushing 3 jobs'
        );
    }

    /**
     * Declare the test queue, bind it to the configured exchange, and purge
     * any leftover messages. The vendor publishes to amq.direct with a routing
     * key matching the queue name, but it does NOT auto-bind the queue. In a
     * real deployment ops provisions the binding; for tests we do it here so
     * messages we publish actually land in the queue rather than being
     * discarded by the exchange.
     *
     * We do this via a fresh AMQP connection (not via the queue driver)
     * because teardown must succeed even when the driver's channel state has
     * been torn down by a failing test.
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
                // Declare durable so the queue survives container restarts
                // during a test run. passive=false so we create-if-missing.
                $channel->queue_declare(self::TEST_QUEUE, false, true, false, false);
                // Bind to amq.direct with routing key == queue name (matches
                // the test config in tests/TestCase.php).
                $channel->queue_bind(self::TEST_QUEUE, 'amq.direct', self::TEST_QUEUE);
                $channel->queue_purge(self::TEST_QUEUE);
            } finally {
                $channel->close();
            }
        } finally {
            $connection->close();
        }
    }
}
