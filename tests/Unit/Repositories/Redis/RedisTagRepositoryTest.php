<?php

namespace Admnio\Sunset\Tests\Unit\Repositories\Redis;

use Admnio\Sunset\Repositories\Redis\RedisTagRepository;
use Admnio\Sunset\Tests\TestCase;
use Illuminate\Contracts\Redis\Factory as RedisFactory;

class RedisTagRepositoryTest extends TestCase
{
    private RedisTagRepository $repo;
    private $redis;

    protected function setUp(): void
    {
        parent::setUp();
        $factory = $this->app->make(RedisFactory::class);
        $this->redis = $factory->connection('default');
        foreach ($this->redis->keys('sunset:*') as $key) {
            $name = str_replace($this->redis->_prefix(''), '', $key);
            $this->redis->del($name);
        }
        $this->repo = new RedisTagRepository($factory);
    }

    public function test_add_permanent_indexes_jobs_by_tag(): void
    {
        $this->repo->addPermanent('job-1', ['email', 'critical']);

        $this->assertContains('job-1', $this->redis->zrange('sunset:tag:email', 0, -1));
        $this->assertContains('job-1', $this->redis->zrange('sunset:tag:critical', 0, -1));
    }

    public function test_add_temporary_sets_expiry(): void
    {
        $expiresAt = time() + 60;
        $this->repo->addTemporary($expiresAt, 'job-2', ['payments']);

        $this->assertContains('job-2', $this->redis->zrange('sunset:tag:payments', 0, -1));
        $ttl = $this->redis->ttl('sunset:tag:payments');
        $this->assertGreaterThan(0, $ttl);
        $this->assertLessThanOrEqual(60, $ttl);
    }

    public function test_jobs_returns_job_ids_for_tag(): void
    {
        $this->repo->addPermanent('a', ['tag-x']);
        $this->repo->addPermanent('b', ['tag-x']);

        $ids = $this->repo->jobs('tag-x')->all();
        $this->assertContains('a', $ids);
        $this->assertContains('b', $ids);
    }

    public function test_count_returns_zset_cardinality(): void
    {
        $this->repo->addPermanent('a', ['t']);
        $this->repo->addPermanent('b', ['t']);
        $this->assertSame(2, $this->repo->count('t'));
    }

    public function test_for_jobs_returns_tags_per_job_id(): void
    {
        $this->repo->addPermanent('j1', ['x', 'y']);
        $this->repo->addPermanent('j2', ['z']);

        $byJob = $this->repo->forJobs(['j1', 'j2']);

        $this->assertEqualsCanonicalizing(['x', 'y'], $byJob['j1']);
        $this->assertSame(['z'], $byJob['j2']);
    }

    public function test_monitor_unmonitor_round_trip(): void
    {
        $this->assertFalse($this->repo->isMonitoring('vip'));

        $this->repo->monitor('vip');
        $this->assertTrue($this->repo->isMonitoring('vip'));
        $this->assertContains('vip', $this->repo->monitored());

        $this->repo->stopMonitoring('vip');
        $this->assertFalse($this->repo->isMonitoring('vip'));
    }

    public function test_add_temporary_with_empty_tags_is_a_noop(): void
    {
        // Should not throw a Redis protocol error.
        $this->repo->addTemporary(time() + 60, 'job-empty', []);

        $this->assertSame([], $this->redis->smembers('sunset:job:job-empty:tags'));
    }

    public function test_forget_removes_tag_index_and_clears_per_job_back_references(): void
    {
        $this->repo->addPermanent('a', ['gone']);
        $this->repo->forget('gone');

        $this->assertSame(0, $this->redis->exists('sunset:tag:gone'));
        $this->assertEmpty($this->repo->forJobs(['a'])['a'] ?? []);
    }
}
