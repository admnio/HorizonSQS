<?php

namespace Admnio\Sunset\Tests\Unit\Repositories\Redis;

use Admnio\Sunset\Repositories\Redis\RedisSupervisorRepository;
use Admnio\Sunset\Tests\TestCase;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Redis\Factory as RedisFactory;

class RedisSupervisorRepositoryTest extends TestCase
{
    private RedisSupervisorRepository $repo;
    private $redis;

    protected function setUp(): void
    {
        parent::setUp();
        $factory = $this->app->make(RedisFactory::class);
        $this->redis = $factory->connection('default');
        // Clean ANY sunset:* keys from prior runs
        foreach ($this->redis->keys('sunset:*') as $key) {
            $name = str_replace($this->redis->_prefix(''), '', $key);
            $this->redis->del($name);
        }
        $this->repo = new RedisSupervisorRepository($factory);
    }

    public function test_names_returns_recently_updated_supervisors(): void
    {
        $this->redis->zadd('sunset:supervisors', (float) CarbonImmutable::now()->getTimestamp(), 'master-1:supervisor-1');

        $names = $this->repo->names();

        $this->assertContains('master-1:supervisor-1', $names);
    }

    public function test_names_excludes_expired_supervisors(): void
    {
        $oldTimestamp = (float) CarbonImmutable::now()->subSeconds(35)->getTimestamp();
        $this->redis->zadd('sunset:supervisors', $oldTimestamp, 'old-supervisor');

        $names = $this->repo->names();

        $this->assertNotContains('old-supervisor', $names);
    }

    public function test_update_stores_supervisor_hash_and_adds_to_set(): void
    {
        // \Admnio\Sunset\Supervisor\Supervisor doesn't exist yet (Task 12). Use
        // Mockery to create a runtime stand-in for testing the repository.
        $options = \Mockery::mock('Admnio\Sunset\Supervisor\SupervisorOptions');
        $options->connection = 'sqs';
        $options->shouldReceive('toJson')->andReturn('{"timeout":60}');

        $supervisor = \Mockery::mock('Admnio\Sunset\Supervisor\Supervisor');
        $supervisor->name = 'master-1:supervisor-1';
        $supervisor->working = true;
        $supervisor->options = $options;
        $supervisor->processPools = collect([]);
        $supervisor->shouldReceive('pid')->andReturn(1234);

        $this->repo->update($supervisor);

        $hash = $this->redis->hgetall('sunset:supervisor:master-1:supervisor-1');
        $this->assertSame('master-1:supervisor-1', $hash['name']);
        $this->assertSame('1234', $hash['pid']);
        $this->assertSame('running', $hash['status']);
        $this->assertSame('master-1', $hash['master']);

        $score = $this->redis->zscore('sunset:supervisors', 'master-1:supervisor-1');
        $this->assertNotNull($score);
    }

    public function test_update_sets_paused_status_when_not_working(): void
    {
        $options = \Mockery::mock('Admnio\Sunset\Supervisor\SupervisorOptions');
        $options->connection = 'sqs';
        $options->shouldReceive('toJson')->andReturn('{}');

        $supervisor = \Mockery::mock('Admnio\Sunset\Supervisor\Supervisor');
        $supervisor->name = 'master-1:sup-paused';
        $supervisor->working = false;
        $supervisor->options = $options;
        $supervisor->processPools = collect([]);
        $supervisor->shouldReceive('pid')->andReturn(9999);

        $this->repo->update($supervisor);

        $hash = $this->redis->hgetall('sunset:supervisor:master-1:sup-paused');
        $this->assertSame('paused', $hash['status']);
    }

    public function test_find_returns_supervisor_by_name(): void
    {
        $options = \Mockery::mock('Admnio\Sunset\Supervisor\SupervisorOptions');
        $options->connection = 'sqs';
        $options->shouldReceive('toJson')->andReturn('{"timeout":90}');

        $supervisor = \Mockery::mock('Admnio\Sunset\Supervisor\Supervisor');
        $supervisor->name = 'master-2:sup-find';
        $supervisor->working = true;
        $supervisor->options = $options;
        $supervisor->processPools = collect([]);
        $supervisor->shouldReceive('pid')->andReturn(5678);

        $this->repo->update($supervisor);

        $found = $this->repo->find('master-2:sup-find');

        $this->assertNotNull($found);
        $this->assertSame('master-2:sup-find', $found['name']);
        $this->assertSame('master-2', $found['master']);
    }

    public function test_find_returns_null_for_missing_supervisor(): void
    {
        $found = $this->repo->find('does-not-exist');
        $this->assertNull($found);
    }

    public function test_all_returns_array_of_active_supervisors(): void
    {
        $this->seedSupervisor('master-1:sup-a', 100);
        $this->seedSupervisor('master-1:sup-b', 200);

        $all = $this->repo->all();

        $this->assertCount(2, $all);
        $names = array_column($all, 'name');
        $this->assertContains('master-1:sup-a', $names);
        $this->assertContains('master-1:sup-b', $names);
    }

    public function test_longest_active_timeout_returns_max_timeout(): void
    {
        $this->redis->hmset('sunset:supervisor:master-1:sup-x', [
            'name' => 'master-1:sup-x',
            'master' => 'master-1',
            'pid' => '100',
            'status' => 'running',
            'processes' => '{}',
            'options' => json_encode(['timeout' => 60]),
        ]);
        $this->redis->zadd('sunset:supervisors', (float) CarbonImmutable::now()->getTimestamp(), 'master-1:sup-x');

        $this->redis->hmset('sunset:supervisor:master-1:sup-y', [
            'name' => 'master-1:sup-y',
            'master' => 'master-1',
            'pid' => '200',
            'status' => 'running',
            'processes' => '{}',
            'options' => json_encode(['timeout' => 300]),
        ]);
        $this->redis->zadd('sunset:supervisors', (float) CarbonImmutable::now()->getTimestamp(), 'master-1:sup-y');

        $timeout = $this->repo->longestActiveTimeout();

        $this->assertSame(300, $timeout);
    }

    public function test_forget_removes_supervisors_by_name_array(): void
    {
        $this->seedSupervisor('master-1:sup-forget-a', 100);
        $this->seedSupervisor('master-1:sup-forget-b', 200);

        $this->repo->forget(['master-1:sup-forget-a', 'master-1:sup-forget-b']);

        $this->assertNull($this->repo->find('master-1:sup-forget-a'));
        $this->assertNull($this->repo->find('master-1:sup-forget-b'));
    }

    public function test_forget_accepts_single_string_name(): void
    {
        $this->seedSupervisor('master-1:sup-single', 300);

        $this->repo->forget('master-1:sup-single');

        $this->assertNull($this->repo->find('master-1:sup-single'));
    }

    public function test_flush_expired_removes_old_supervisors(): void
    {
        $oldTimestamp = (float) CarbonImmutable::now()->subSeconds(20)->getTimestamp();
        $this->redis->zadd('sunset:supervisors', $oldTimestamp, 'expired-supervisor');
        $this->redis->zadd('sunset:supervisors', (float) CarbonImmutable::now()->getTimestamp(), 'fresh-supervisor');

        $this->repo->flushExpired();

        $remaining = $this->redis->zrange('sunset:supervisors', 0, -1);
        $this->assertNotContains('expired-supervisor', $remaining);
        $this->assertContains('fresh-supervisor', $remaining);
    }

    private function seedSupervisor(string $name, int $pid): void
    {
        $options = \Mockery::mock('Admnio\Sunset\Supervisor\SupervisorOptions');
        $options->connection = 'sqs';
        $options->shouldReceive('toJson')->andReturn('{"timeout":60}');

        $supervisor = \Mockery::mock('Admnio\Sunset\Supervisor\Supervisor');
        $supervisor->name = $name;
        $supervisor->working = true;
        $supervisor->options = $options;
        $supervisor->processPools = collect([]);
        $supervisor->shouldReceive('pid')->andReturn($pid);

        $this->repo->update($supervisor);
    }
}
