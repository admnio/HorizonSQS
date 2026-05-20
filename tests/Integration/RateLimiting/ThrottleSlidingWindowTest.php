<?php

namespace Admnio\Sunset\Tests\Integration\RateLimiting;

use Admnio\Sunset\Events\JobRateLimited;
use Admnio\Sunset\Facades\Sunset;
use Admnio\Sunset\RateLimiting\LimitRegistry;
use Admnio\Sunset\Tests\Fixtures\Jobs\RecordingJob;
use Admnio\Sunset\Tests\Integration\IntegrationTestCase;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

/**
 * v0.7.0 — proves the sliding-window throttle admits N jobs in a window and
 * releases the (N+1)-th with a bounded retry-after.
 *
 * Run against real Redis (db 1) via the redis queue driver. Every test resets
 * the LimitRegistry singleton and wipes any leftover sunset:rl:* keys so prior
 * tests' state cannot leak across runs.
 */
class ThrottleSlidingWindowTest extends IntegrationTestCase
{
    private const TEST_QUEUE = 'rl-throttle';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Fresh registry per test — Manager::for() declarations are otherwise
        // sticky across tests because LimitRegistry is a singleton.
        $this->app->forgetInstance(LimitRegistry::class);

        $this->purgeRedisRlState();
        $this->purgeTestQueue();

        config([
            'queue.default' => 'redis',
            'queue.connections.redis.queue' => self::TEST_QUEUE,
        ]);

        @unlink(sys_get_temp_dir() . '/sunset-marker');
    }

    protected function tearDown(): void
    {
        try {
            $this->purgeRedisRlState();
            $this->purgeTestQueue();
        } catch (\Throwable $e) {
            // Best-effort cleanup; Redis may already be down.
        }

        @unlink(sys_get_temp_dir() . '/sunset-marker');

        parent::tearDown();
    }

    public function test_eleven_jobs_against_perMinute_10_admits_ten_releases_one(): void
    {
        Sunset::for(self::TEST_QUEUE)->throttle(perMinute: 10);

        for ($i = 0; $i < 11; $i++) {
            Queue::push(new RecordingJob("throttle-{$i}"));
        }

        $admitted = 0;
        $released = 0;

        for ($i = 0; $i < 11; $i++) {
            $job = Queue::connection('redis')->pop(self::TEST_QUEUE);
            if ($job === null) {
                $released++;
                continue;
            }
            $admitted++;
            // Delete each admitted job so we don't leak Redis-queue reserved
            // entries between tests (the gate already released the rejected
            // one via $job->release()).
            $job->delete();
        }

        $this->assertSame(
            10,
            $admitted,
            "Expected exactly 10 admits against perMinute:10; got {$admitted}."
        );
        $this->assertSame(
            1,
            $released,
            "Expected exactly 1 throttle-release against perMinute:10; got {$released}."
        );
    }

    public function test_retry_after_is_bounded_within_window(): void
    {
        Sunset::for(self::TEST_QUEUE)->throttle(perMinute: 10);

        for ($i = 0; $i < 11; $i++) {
            Queue::push(new RecordingJob("retry-{$i}"));
        }

        Event::fake([JobRateLimited::class]);

        for ($i = 0; $i < 11; $i++) {
            $job = Queue::connection('redis')->pop(self::TEST_QUEUE);
            if ($job !== null) {
                $job->delete();
            }
        }

        Event::assertDispatched(JobRateLimited::class, function (JobRateLimited $e) {
            // retryAfter must be a positive value bounded by the 60-second
            // window. The Lua script returns ceil((oldest + window) - now),
            // and oldest is always within [now-window, now], so the result
            // is in [1, 60].
            return $e->retryAfterSeconds >= 1
                && $e->retryAfterSeconds <= 60
                && $e->queueName === self::TEST_QUEUE;
        });
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

    private function detectPrefix($conn): string
    {
        try {
            return (string) $conn->client()->getOption(\Redis::OPT_PREFIX);
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function purgeTestQueue(): void
    {
        /** @var RedisFactory $factory */
        $factory = $this->app->make(RedisFactory::class);
        $conn = $factory->connection('default');
        $conn->del('queues:' . self::TEST_QUEUE);
        $conn->del('queues:' . self::TEST_QUEUE . ':delayed');
        $conn->del('queues:' . self::TEST_QUEUE . ':reserved');
        $conn->del('queues:' . self::TEST_QUEUE . ':notify');
    }

}
