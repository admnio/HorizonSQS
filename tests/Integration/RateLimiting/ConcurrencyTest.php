<?php

namespace Admnio\Sunset\Tests\Integration\RateLimiting;

use Admnio\Sunset\Facades\Sunset;
use Admnio\Sunset\RateLimiting\LimitRegistry;
use Admnio\Sunset\Tests\Fixtures\Jobs\RecordingJob;
use Admnio\Sunset\Tests\Integration\IntegrationTestCase;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Facades\Queue;

/**
 * v0.7.0 — concurrency limiter caps in-flight jobs and frees the slot when the
 * job lifecycle event fires.
 *
 * Note: do NOT call $job->delete() on an admitted job in these tests — that
 * does not fire JobProcessed in the Redis driver's pop path, so the slot
 * stays held. Use $job->fire() to drive the full pipeline.
 */
class ConcurrencyTest extends IntegrationTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->forgetInstance(LimitRegistry::class);
        $this->purgeRedisRlState();

        config(['queue.default' => 'redis']);

        @unlink(sys_get_temp_dir() . '/sunset-marker');
    }

    protected function tearDown(): void
    {
        try {
            $this->purgeRedisRlState();
            $this->purgeAllTestQueues();
        } catch (\Throwable $e) {
            // best-effort
        }

        @unlink(sys_get_temp_dir() . '/sunset-marker');

        parent::tearDown();
    }

    public function test_three_concurrency_admits_three_blocks_more(): void
    {
        $queue = 'rl-conc';
        config(['queue.connections.redis.queue' => $queue]);
        $this->purgeQueue($queue);

        Sunset::for($queue)->concurrency(3);

        for ($i = 0; $i < 5; $i++) {
            Queue::push(new RecordingJob("conc-{$i}"));
        }

        $admitted = [];
        $released = 0;

        for ($i = 0; $i < 5; $i++) {
            $job = Queue::connection('redis')->pop($queue);
            if ($job === null) {
                $released++;
            } else {
                // CRITICAL: do NOT delete — slots remain held until JobProcessed.
                $admitted[] = $job;
            }
        }

        $this->assertCount(
            3,
            $admitted,
            'Expected exactly 3 admits against concurrency:3.'
        );
        $this->assertSame(
            2,
            $released,
            'Expected exactly 2 releases against concurrency:3.'
        );
    }

    public function test_slot_freed_after_job_processed_event(): void
    {
        $queue = 'rl-conc2';
        config(['queue.connections.redis.queue' => $queue]);
        $this->purgeQueue($queue);

        Sunset::for($queue)->concurrency(1);

        Queue::push(new RecordingJob('first'));
        Queue::push(new RecordingJob('second'));

        $first = Queue::connection('redis')->pop($queue);
        $this->assertNotNull($first, 'First pop must admit the only slot.');

        $second = Queue::connection('redis')->pop($queue);
        $this->assertNull(
            $second,
            'Second pop must be released — the single slot is held by the first job.'
        );

        // Fire the first job so its handler runs; then dispatch JobProcessed
        // by hand. The Worker normally fires this event after process() — we
        // simulate that here so the ReleaseConcurrencySlots listener fires
        // and frees the concurrency slot.
        $first->fire();
        $first->delete();
        event(new \Illuminate\Queue\Events\JobProcessed('redis', $first));

        // The first job's body wrote its marker; sanity-check the pipeline ran.
        $this->assertSame(
            'first',
            file_get_contents(sys_get_temp_dir() . '/sunset-marker'),
            'Sanity: the first job should have run and written its marker.'
        );

        // Push a third job (re-using the now-freed slot) and prove it admits.
        Queue::push(new RecordingJob('third'));

        $thirdAdmitted = Queue::connection('redis')->pop($queue);

        $this->assertNotNull(
            $thirdAdmitted,
            'After JobProcessed fired and the slot was freed, the next pop must admit.'
        );
    }

    private function purgeRedisRlState(): void
    {
        /** @var RedisFactory $factory */
        $factory = $this->app->make(RedisFactory::class);
        $conn = $factory->connection('default');

        // phpredis returns prefixed keys from KEYS but RE-prepends the prefix
        // on del(), so we must strip it. Do this via the underlying client's
        // option API rather than guessing the prefix name.
        $prefix = $this->detectPrefix($conn);

        foreach ((array) $conn->keys('sunset:rl:*') as $key) {
            $bare = ($prefix !== '' && str_starts_with($key, $prefix))
                ? substr($key, strlen($prefix))
                : $key;
            $conn->del($bare);
        }
    }

    private function detectPrefix($conn): string
    {
        try {
            return (string) $conn->client()->getOption(\Redis::OPT_PREFIX);
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function purgeQueue(string $name): void
    {
        /** @var RedisFactory $factory */
        $factory = $this->app->make(RedisFactory::class);
        $conn = $factory->connection('default');
        $conn->del("queues:{$name}");
        $conn->del("queues:{$name}:delayed");
        $conn->del("queues:{$name}:reserved");
        $conn->del("queues:{$name}:notify");
    }

    private function purgeAllTestQueues(): void
    {
        foreach (['rl-conc', 'rl-conc2'] as $name) {
            $this->purgeQueue($name);
        }
    }

}
