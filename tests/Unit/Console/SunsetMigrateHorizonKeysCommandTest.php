<?php

namespace Admnio\Sunset\Tests\Unit\Console;

use Admnio\Sunset\Tests\TestCase;
use Illuminate\Contracts\Redis\Factory as RedisFactory;

class SunsetMigrateHorizonKeysCommandTest extends TestCase
{
    private $redis;

    protected function setUp(): void
    {
        parent::setUp();
        $this->redis = $this->app->make(RedisFactory::class)->connection('default');
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        foreach (['horizon:*', 'sunset:*'] as $pattern) {
            foreach ($this->redis->keys($pattern) as $key) {
                $name = str_replace($this->redis->_prefix(''), '', $key);
                $this->redis->del($name);
            }
        }
    }

    public function test_renames_string_keys(): void
    {
        $this->redis->set('horizon:foo', 'bar');

        $this->artisan('sunset:migrate-horizon-keys')->assertExitCode(0);

        $this->assertSame('bar', $this->redis->get('sunset:foo'));
        $this->assertNull($this->redis->get('horizon:foo'));
    }

    public function test_renames_zset_keys(): void
    {
        $this->redis->zadd('horizon:recent_jobs', 1, 'job-1');
        $this->redis->zadd('horizon:recent_jobs', 2, 'job-2');

        $this->artisan('sunset:migrate-horizon-keys')->assertExitCode(0);

        $this->assertSame(2, $this->redis->zcard('sunset:recent_jobs'));
        $this->assertSame(0, $this->redis->exists('horizon:recent_jobs'));
    }

    public function test_rewrites_per_job_hashes_with_job_infix(): void
    {
        // Horizon stores per-job hashes at horizon:{id}, NOT horizon:job:{id}.
        // Our target schema uses sunset:job:{id} explicitly.
        $this->redis->hmset('horizon:abc-123', ['id' => 'abc-123', 'payload' => '{}', 'status' => 'pending']);

        $this->artisan('sunset:migrate-horizon-keys')->assertExitCode(0);

        $hash = $this->redis->hgetall('sunset:job:abc-123');
        $this->assertSame('abc-123', $hash['id']);
        $this->assertSame('pending', $hash['status']);
        $this->assertSame(0, $this->redis->exists('horizon:abc-123'));
    }

    public function test_dry_run_does_not_modify_keys(): void
    {
        $this->redis->set('horizon:foo', 'bar');

        $this->artisan('sunset:migrate-horizon-keys', ['--dry-run' => true])->assertExitCode(0);

        $this->assertSame('bar', $this->redis->get('horizon:foo'));
        $this->assertNull($this->redis->get('sunset:foo'));
    }

    public function test_is_idempotent(): void
    {
        $this->redis->set('horizon:foo', 'bar');
        $this->artisan('sunset:migrate-horizon-keys')->assertExitCode(0);
        $this->artisan('sunset:migrate-horizon-keys')->assertExitCode(0); // second run no-op

        $this->assertSame('bar', $this->redis->get('sunset:foo'));
    }

    public function test_preserves_ttl(): void
    {
        $this->redis->set('horizon:ttl-key', 'value');
        $this->redis->expire('horizon:ttl-key', 300);

        $this->artisan('sunset:migrate-horizon-keys')->assertExitCode(0);

        $ttl = $this->redis->ttl('sunset:ttl-key');
        $this->assertGreaterThan(0, $ttl);
        $this->assertLessThanOrEqual(300, $ttl);
    }
}
