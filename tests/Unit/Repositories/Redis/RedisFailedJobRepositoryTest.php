<?php

namespace Admnio\Sunset\Tests\Unit\Repositories\Redis;

use Admnio\Sunset\JobPayload;
use Admnio\Sunset\Repositories\Redis\RedisFailedJobRepository;
use Admnio\Sunset\Tests\TestCase;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use RuntimeException;

class RedisFailedJobRepositoryTest extends TestCase
{
    private RedisFailedJobRepository $repo;
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
        $this->repo = new RedisFailedJobRepository($factory);
    }

    public function test_failed_writes_failed_index_and_job_hash(): void
    {
        $payload = $this->payload(['uuid' => 'f-1', 'displayName' => 'BrokenJob']);
        $exception = new RuntimeException('boom');

        $this->repo->failed($exception, 'sqs', 'orders', $payload);

        $this->assertSame(1, $this->redis->zcard('sunset:failed_jobs'));
        $this->assertSame(1, $this->redis->zcard('sunset:recent_failed_jobs'));
        $hash = $this->redis->hgetall('sunset:job:f-1');
        $this->assertSame('failed', $hash['status']);
        $this->assertSame('orders', $hash['queue']);
        $this->assertStringContainsString('RuntimeException', $hash['exception']);
        $this->assertSame('boom', json_decode($hash['exception'], true)['message'] ?? '');
        $this->assertNotEmpty($hash['failed_at']);
    }

    public function test_find_failed_returns_decoded_hash(): void
    {
        $this->repo->failed(new RuntimeException('x'), 'sqs', 'q', $this->payload(['uuid' => 'f-2']));

        $found = $this->repo->findFailed('f-2');

        $this->assertNotNull($found);
        $this->assertSame('f-2', $found->id);
        $this->assertSame('failed', $found->status);
    }

    public function test_find_failed_returns_null_for_missing(): void
    {
        $this->assertNull($this->repo->findFailed('does-not-exist'));
    }

    public function test_counts(): void
    {
        $this->repo->failed(new RuntimeException(), 'sqs', 'q', $this->payload(['uuid' => 'a']));
        $this->repo->failed(new RuntimeException(), 'sqs', 'q', $this->payload(['uuid' => 'b']));

        $this->assertSame(2, $this->repo->countFailed());
        $this->assertSame(2, $this->repo->totalFailed());
        $this->assertSame(2, $this->repo->countRecentlyFailed());
    }

    public function test_delete_failed_removes_index_entry_and_hash(): void
    {
        $this->repo->failed(new RuntimeException(), 'sqs', 'q', $this->payload(['uuid' => 'gone']));
        $removed = $this->repo->deleteFailed('gone');

        $this->assertSame(1, $removed);
        $this->assertSame(0, $this->redis->zcard('sunset:failed_jobs'));
        $this->assertSame(0, $this->redis->zcard('sunset:recent_failed_jobs'));
        $this->assertSame([], $this->redis->hgetall('sunset:job:gone'));
    }

    public function test_get_failed_returns_jobs_newest_first(): void
    {
        $this->repo->failed(new RuntimeException(), 'sqs', 'q', $this->payload(['uuid' => 'older']));
        usleep(1000);
        $this->repo->failed(new RuntimeException(), 'sqs', 'q', $this->payload(['uuid' => 'newer']));

        $jobs = $this->repo->getFailed();
        $this->assertCount(2, $jobs);
        $this->assertSame('newer', $jobs->first()->id);
    }

    private function payload(array $decoded): JobPayload
    {
        $decoded += ['displayName' => 'TestJob', 'data' => [], 'tags' => []];
        return new JobPayload(json_encode($decoded));
    }
}
