<?php

namespace Admnio\Sunset\Tests\Integration;

use Admnio\Sunset\Events\JobQueued;
use Admnio\Sunset\Events\JobQueueing;
use Admnio\Sunset\Support\TransportRegistry;
use Admnio\Sunset\Tests\Fixtures\Jobs\RecordingJob;
use Admnio\Sunset\Transports\Rabbit\RabbitTransport;
use Admnio\Sunset\Transports\Sqs\Delay\DelayedJobStore;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use PhpAmqpLib\Connection\AMQPStreamConnection;

/**
 * Proves that RabbitQueue::later() routes delayed dispatches through Sunset's
 * Redis-backed DelayedJobStore — the same buffer SQS has used since v0.2.0 for
 * delays exceeding SQS's 15-minute native cap.
 *
 * The integration-level guarantees are:
 *  - the job lands in `sunset:delayed` (ZSET) with the configured queue name
 *  - the RabbitMQ queue depth stays at 0 (no AMQP publish happened, neither
 *    via the vendor's per-TTL holding-queue trick nor the delayed-exchange
 *    plugin)
 *  - later()'s return value is the prepared payload's canonical uuid (not a
 *    synthetic Str::random(40)), so dispatch sites get a real persistent id
 *    tied to the buffered job
 *  - JobQueueing + JobQueued fire at buffer time, so the dashboard shows the
 *    delayed job immediately rather than waiting for the reaper to promote it
 */
class RabbitDelayedTest extends IntegrationTestCase
{
    private const TEST_QUEUE = 'sunset-rabbit-delayed-test';
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
    }

    protected function tearDown(): void
    {
        // Best-effort cleanup so a failing test doesn't poison the next run.
        try {
            $this->purgeTestQueue();
        } catch (\Throwable $e) {
            // Swallow — RabbitMQ may already be down at teardown time.
        }

        try {
            $this->purgeDelayedStore();
        } catch (\Throwable $e) {
            // Same idea for Redis.
        }

        parent::tearDown();
    }

    public function test_later_writes_to_DelayedJobStore_not_rabbitmq(): void
    {
        Queue::connection('rabbitmq')->later(120, new RecordingJob('delayed-marker'));

        /** @var DelayedJobStore $store */
        $store = $this->app->make(DelayedJobStore::class);

        $entries = collect($store->due(time() + 86400))
            ->where('queue', self::TEST_QUEUE)
            ->values()
            ->all();

        $this->assertCount(
            1,
            $entries,
            'Expected exactly 1 buffered entry for ' . self::TEST_QUEUE
        );

        $decoded = json_decode($entries[0]['payload'], true);
        $this->assertIsArray(
            $decoded,
            'Buffered payload should be valid JSON produced by JobPayload::prepare()'
        );
        $this->assertArrayHasKey('uuid', $decoded, 'JobPayload::prepare() injects a uuid');
        $this->assertArrayHasKey('displayName', $decoded);
        $this->assertStringContainsString(
            'RecordingJob',
            (string) $decoded['displayName'],
            'displayName should reference the dispatched job class'
        );

        // The AMQP queue depth proves the job NEVER reached RabbitMQ.
        /** @var RabbitTransport $transport */
        $transport = $this->app->make(TransportRegistry::class)->get('rabbitmq');
        $workload = $transport->workload([self::TEST_QUEUE]);

        $this->assertSame(self::TEST_QUEUE, $workload[0]['name']);
        $this->assertSame(
            0,
            $workload[0]['length'],
            'Delayed job must not be published to RabbitMQ — it belongs to Sunset DelayedJobStore'
        );
    }

    public function test_later_returns_a_real_payload_id_not_a_random_string(): void
    {
        $id = Queue::connection('rabbitmq')->later(60, new RecordingJob('id-check'));

        /** @var DelayedJobStore $store */
        $store = $this->app->make(DelayedJobStore::class);

        $entries = collect($store->due(time() + 86400))
            ->where('queue', self::TEST_QUEUE)
            ->values()
            ->all();

        $this->assertCount(1, $entries, 'Expected one buffered entry on ' . self::TEST_QUEUE);

        $decoded = json_decode($entries[0]['payload'], true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('uuid', $decoded);

        $this->assertIsString($id);
        $this->assertNotEmpty($id);
        $this->assertSame(
            $decoded['uuid'],
            $id,
            'later() must return the prepared payload uuid, not a synthetic random string'
        );
    }

    public function test_later_fires_JobQueueing_and_JobQueued_events(): void
    {
        Event::fake([JobQueueing::class, JobQueued::class]);

        Queue::connection('rabbitmq')->later(60, new RecordingJob('events-test'));

        Event::assertDispatched(JobQueueing::class, function ($e) {
            return $e->connectionName === 'rabbitmq' && $e->queue === self::TEST_QUEUE;
        });
        Event::assertDispatched(JobQueued::class, function ($e) {
            return $e->connectionName === 'rabbitmq' && $e->queue === self::TEST_QUEUE;
        });
    }

    /**
     * Declare, bind, and purge the test queue. Mirrors RabbitRoundtripTest's
     * helper — kept local so each integration test class owns its cleanup and
     * can run independently. amq.direct is configured on the queue connection
     * in TestCase::defineEnvironment; we use a routing key matching the queue
     * name.
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
     * key rather than per-entry because the test owns its Redis database
     * (database 1, configured in TestCase) and no other test data overlaps.
     */
    private function purgeDelayedStore(): void
    {
        /** @var RedisFactory $factory */
        $factory = $this->app->make(RedisFactory::class);
        $factory->connection('default')->del(self::DELAYED_KEY);
    }
}
