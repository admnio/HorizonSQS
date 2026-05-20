<?php

namespace Admnio\Sunset\Tests\Integration\RateLimiting;

use Admnio\Sunset\Contracts\Limiter;
use Admnio\Sunset\RateLimiting\LimitRegistry;
use Admnio\Sunset\RateLimiting\RedisLimiter;
use Admnio\Sunset\Tests\Integration\IntegrationTestCase;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Facades\Artisan;

/**
 * v0.7.0 — proves the sunset:sweep-rate-limit-slots command removes orphan
 * slot members from concurrency sets when their paired slot keys have
 * expired (or never existed). This is the safety net for the rare case
 * where a worker process is hard-killed between the slot SADD and the
 * JobProcessed event that would normally release it.
 */
class SweepRecoveryTest extends IntegrationTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));

        // Laravel's phpredis client applies a default prefix ("laravel_database_")
        // pulled from database.redis.options.prefix when not set explicitly.
        // The SunsetSweepRateLimitSlotsCommand calls $conn->keys() and feeds
        // the result back into a Lua eval; with a prefix configured, phpredis
        // re-prefixes the keys returned by keys(), causing the eval to look
        // up a double-prefixed (non-existent) key. Clearing the prefix here
        // keeps the command's wire-level key lookups straight. Test-local
        // override only — other tests retain their existing Redis prefix.
        $app['config']->set('database.redis.options.prefix', '');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->forgetInstance(LimitRegistry::class);

        // SunsetSweepRateLimitSlotsCommand type-hints RedisLimiter concretely,
        // not the Limiter contract. The service provider only binds the
        // contract, so resolving the command via Artisan blows up with an
        // unresolvable-dependency error. Make the concrete class resolve to
        // the same singleton the contract is bound to so the command works.
        $this->app->instance(
            RedisLimiter::class,
            $this->app->make(Limiter::class),
        );

        $this->purgeRedisRlState();
    }

    protected function tearDown(): void
    {
        try {
            $this->purgeRedisRlState();
        } catch (\Throwable $e) {
            // best-effort
        }
        parent::tearDown();
    }

    public function test_sunset_sweep_rate_limit_slots_removes_orphaned_set_members(): void
    {
        /** @var RedisFactory $factory */
        $factory = $this->app->make(RedisFactory::class);
        $conn = $factory->connection('default');

        $setKey = 'sunset:rl:c:test:bucket';
        $orphanSlot = 'fakeSlot1';

        // Inject an orphan: a member in the concurrency set with NO paired
        // sunset:rl:slot:fakeSlot1 key. This mimics the post-crash state
        // the sweep command is built to repair.
        $conn->sadd($setKey, $orphanSlot);
        $this->assertSame(
            1,
            (int) $conn->scard($setKey),
            'Pre-condition: orphan was injected into the concurrency set.'
        );

        Artisan::call('sunset:sweep-rate-limit-slots');
        $output = Artisan::output();

        $this->assertSame(
            0,
            (int) $conn->scard($setKey),
            'Sweep must remove the orphan because its paired slot key never existed.'
        );

        $this->assertStringContainsString(
            '1',
            $output,
            'Sweep command output should reference the 1 orphan it removed.'
        );
        $this->assertStringContainsString(
            'Swept',
            $output,
            'Sweep command output should follow the "Swept N orphaned slot(s)" format.'
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

    private function detectPrefix($conn): string
    {
        try {
            return (string) $conn->client()->getOption(\Redis::OPT_PREFIX);
        } catch (\Throwable $e) {
            return '';
        }
    }
}
