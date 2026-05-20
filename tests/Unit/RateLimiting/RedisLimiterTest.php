<?php

namespace Admnio\Sunset\Tests\Unit\RateLimiting;

use Admnio\Sunset\RateLimiting\ConcurrencySpec;
use Admnio\Sunset\RateLimiting\Limit;
use Admnio\Sunset\RateLimiting\RedisLimiter;
use Admnio\Sunset\RateLimiting\Targets\QueueTarget;
use Admnio\Sunset\RateLimiting\ThrottleSpec;
use Admnio\Sunset\Tests\TestCase;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Throwable;

class RedisLimiterTest extends TestCase
{
    private RedisLimiter $limiter;
    private mixed $conn;

    protected function setUp(): void
    {
        parent::setUp();

        $factory = $this->app->make(RedisFactory::class);
        $connectionName = config('sunset.redis_connection', 'default');

        try {
            $conn = $factory->connection($connectionName);
            $pong = $conn->ping();
            // phpredis returns true; predis returns Status with "PONG"
            if ($pong !== true && (string) $pong !== 'PONG' && (string) $pong !== '+PONG') {
                $this->markTestSkipped('Redis not reachable (unexpected ping response).');
            }
        } catch (Throwable $e) {
            $this->markTestSkipped('Redis not reachable: ' . $e->getMessage());
        }

        $this->conn = $conn;
        $this->flushSunsetRlKeys();

        $this->limiter = new RedisLimiter($factory, $connectionName);
    }

    protected function tearDown(): void
    {
        try {
            $this->flushSunsetRlKeys();
        } catch (Throwable) {
            // ignore
        }
        parent::tearDown();
    }

    private function flushSunsetRlKeys(): void
    {
        $keys = $this->conn->keys('sunset:rl:*');
        if (! empty($keys)) {
            // Laravel-managed phpredis adds a prefix; the keys() result already
            // strips the prefix on phpredis. del() will re-add it.
            $this->conn->del(...$keys);
        }
    }

    public function test_throttle_admits_up_to_max_then_rejects(): void
    {
        $limit = new Limit(
            name: 'test-throttle',
            target: new QueueTarget('q'),
            throttle: new ThrottleSpec(3, 60),
        );

        $d1 = $this->limiter->check($limit, 'bucket');
        $d2 = $this->limiter->check($limit, 'bucket');
        $d3 = $this->limiter->check($limit, 'bucket');
        $d4 = $this->limiter->check($limit, 'bucket');

        $this->assertTrue($d1->admitted);
        $this->assertTrue($d2->admitted);
        $this->assertTrue($d3->admitted);
        $this->assertFalse($d4->admitted);
        $this->assertGreaterThan(0, $d4->retryAfterSeconds);
        $this->assertLessThanOrEqual(60, $d4->retryAfterSeconds);
    }

    public function test_concurrency_admits_up_to_max_then_rejects(): void
    {
        $limit = new Limit(
            name: 'test-concurrency',
            target: new QueueTarget('q'),
            concurrency: new ConcurrencySpec(2, 120),
        );

        $d1 = $this->limiter->check($limit, 'bucket');
        $d2 = $this->limiter->check($limit, 'bucket');
        $d3 = $this->limiter->check($limit, 'bucket');

        $this->assertTrue($d1->admitted);
        $this->assertTrue($d2->admitted);
        $this->assertFalse($d3->admitted);
        $this->assertGreaterThan(0, $d3->retryAfterSeconds);
    }

    public function test_release_frees_concurrency_slot(): void
    {
        $limit = new Limit(
            name: 'test-release',
            target: new QueueTarget('q'),
            concurrency: new ConcurrencySpec(1, 120),
        );

        $first = $this->limiter->check($limit, 'bucket');
        $this->assertTrue($first->admitted);

        $second = $this->limiter->check($limit, 'bucket');
        $this->assertFalse($second->admitted);

        $this->limiter->release($first->reservations);

        $third = $this->limiter->check($limit, 'bucket');
        $this->assertTrue($third->admitted);
    }

    public function test_rollback_undoes_concurrency_admit(): void
    {
        $limit = new Limit(
            name: 'test-rollback',
            target: new QueueTarget('q'),
            concurrency: new ConcurrencySpec(1, 120),
        );

        $first = $this->limiter->check($limit, 'bucket');
        $this->assertTrue($first->admitted);

        $this->limiter->rollback($first->reservations);

        $second = $this->limiter->check($limit, 'bucket');
        $this->assertTrue($second->admitted);
    }

    public function test_throttle_and_concurrency_both_evaluated_in_one_check(): void
    {
        $limit = new Limit(
            name: 'test-both',
            target: new QueueTarget('q'),
            throttle: new ThrottleSpec(10, 60),
            concurrency: new ConcurrencySpec(1, 120),
        );

        $first = $this->limiter->check($limit, 'bucket');
        $this->assertTrue($first->admitted);
        $this->assertCount(2, $first->reservations);

        $types = array_column($first->reservations, 'type');
        $this->assertContains('throttle', $types);
        $this->assertContains('concurrency', $types);

        // Second check rejects because concurrency cap is 1, even though throttle has room.
        $second = $this->limiter->check($limit, 'bucket');
        $this->assertFalse($second->admitted);
    }

    public function test_reconcileSlots_removes_orphans(): void
    {
        $setKey = 'sunset:rl:c:test-reconcile:bucket';
        $orphanSlot = 'orphan-slot-id';

        // SADD the slot into the set but DO NOT write a paired slot key,
        // simulating a worker that crashed between SADD and SET.
        $this->conn->sadd($setKey, $orphanSlot);

        $removed = $this->limiter->reconcileSlots($setKey);

        $this->assertSame(1, $removed);
        $this->assertSame(0, (int) $this->conn->scard($setKey));
    }
}
