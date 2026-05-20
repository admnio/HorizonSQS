<?php

namespace Admnio\Sunset\Tests\Integration\RateLimiting;

use Admnio\Sunset\Exceptions\RateLimitExceededException;
use Admnio\Sunset\Facades\Sunset;
use Admnio\Sunset\RateLimiting\LimitRegistry;
use Admnio\Sunset\Tests\Fixtures\Jobs\RecordingJob;
use Admnio\Sunset\Tests\Integration\IntegrationTestCase;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

/**
 * v0.7.0 — the `drop` over-limit strategy has two sub-modes:
 *   - dropAsFailure(true)  -> $job->fail(RateLimitExceededException) — counts as failure
 *   - dropAsFailure(false) -> $job->delete() — silent drop, no failed-jobs row
 */
class DropStrategyTest extends IntegrationTestCase
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

    public function test_drop_with_dropAsFailure_true_routes_to_failed_jobs(): void
    {
        $queue = 'rl-drop-fail';
        config(['queue.connections.redis.queue' => $queue]);
        $this->purgeQueue($queue);

        Sunset::for($queue)
            ->throttle(perMinute: 1)
            ->onOverLimit('drop')
            ->dropAsFailure(true);

        Queue::push(new RecordingJob('drop-1'));
        Queue::push(new RecordingJob('drop-2'));

        // First pop admits — burn the single per-minute slot. Delete to remove
        // from the reserved set so it doesn't interfere with the second pop.
        $first = Queue::connection('redis')->pop($queue);
        $this->assertNotNull($first, 'First job must admit against perMinute:1.');
        $first->delete();

        Event::fake([JobFailed::class]);

        // Second pop is rejected → drop+failAs → $job->fail(RateLimitExceededException).
        $second = Queue::connection('redis')->pop($queue);
        $this->assertNull(
            $second,
            'Second job must be rejected by the throttle.'
        );

        Event::assertDispatched(JobFailed::class, function (JobFailed $event) {
            return $event->exception instanceof RateLimitExceededException
                && $event->exception->limitName === 'queue:rl-drop-fail';
        });
    }

    public function test_drop_with_dropAsFailure_false_silently_deletes(): void
    {
        $queue = 'rl-drop-silent';
        config(['queue.connections.redis.queue' => $queue]);
        $this->purgeQueue($queue);

        Sunset::for($queue)
            ->throttle(perMinute: 1)
            ->onOverLimit('drop')
            ->dropAsFailure(false);

        Queue::push(new RecordingJob('silent-1'));
        Queue::push(new RecordingJob('silent-2'));

        $first = Queue::connection('redis')->pop($queue);
        $this->assertNotNull($first, 'First job must admit against perMinute:1.');
        $first->delete();

        Event::fake([JobFailed::class]);

        $second = Queue::connection('redis')->pop($queue);
        $this->assertNull(
            $second,
            'Second job must be rejected and silently deleted.'
        );

        Event::assertNotDispatched(JobFailed::class);

        // Queue depth must be 0 — silent drop calls $job->delete(), removing
        // both the queued copy and the reserved entry.
        /** @var RedisFactory $factory */
        $factory = $this->app->make(RedisFactory::class);
        $conn = $factory->connection('default');
        $depth = $conn->llen("queues:{$queue}");
        $reserved = $conn->zcard("queues:{$queue}:reserved");
        $this->assertSame(
            0,
            (int) $depth + (int) $reserved,
            "Expected queue '{$queue}' to be empty after silent drop; depth={$depth}, reserved={$reserved}."
        );
    }

    private function purgeRedisRlState(): void
    {
        /** @var RedisFactory $factory */
        $factory = $this->app->make(RedisFactory::class);
        $conn = $factory->connection('default');

        $prefix = $this->detectPrefix($conn);

        foreach ((array) $conn->keys('sunset:rl:*') as $key) {
            $bare = ($prefix !== '' && str_starts_with($key, $prefix))
                ? substr($key, strlen($prefix))
                : $key;
            $conn->del($bare);
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
        foreach (['rl-drop-fail', 'rl-drop-silent'] as $name) {
            $this->purgeQueue($name);
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
}
