<?php

namespace Admnio\Sunset\Tests\Unit\Repositories\Redis;

use Admnio\Sunset\JobPayload;
use Admnio\Sunset\Repositories\Redis\RedisJobRepository;
use Admnio\Sunset\Tests\TestCase;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Collection;

class RedisJobRepositoryTest extends TestCase
{
    private RedisJobRepository $repo;
    private $redis;

    protected function setUp(): void
    {
        parent::setUp();
        $factory = $this->app->make(RedisFactory::class);
        $this->redis = $factory->connection('default');
        // Clean ANY sunset:* keys from prior runs
        foreach ($this->redis->keys('sunset:*') as $key) {
            // phpredis may include the database prefix; strip if so
            $name = str_replace($this->redis->_prefix(''), '', $key);
            $this->redis->del($name);
        }
        $this->repo = new RedisJobRepository($factory);
    }

    public function test_next_job_id_increments(): void
    {
        $first = $this->repo->nextJobId();
        $second = $this->repo->nextJobId();
        $this->assertSame((int) $first + 1, (int) $second);
    }

    public function test_pushed_writes_pending_and_recent_indices_with_job_hash(): void
    {
        $payload = $this->preparedPayload(['uuid' => 'job-1', 'displayName' => 'TestJob']);

        $this->repo->pushed('sqs', 'orders', $payload);

        $this->assertSame(1, $this->redis->zcard('sunset:recent_jobs'));
        $this->assertSame(1, $this->redis->zcard('sunset:pending_jobs'));

        $hash = $this->redis->hgetall('sunset:job:job-1');
        $this->assertSame('job-1', $hash['id']);
        $this->assertSame('sqs', $hash['connection']);
        $this->assertSame('orders', $hash['queue']);
        $this->assertSame('TestJob', $hash['name']);
        $this->assertSame('pending', $hash['status']);
        $this->assertNotEmpty($hash['payload']);
    }

    public function test_reserved_moves_job_to_reserved_status(): void
    {
        $payload = $this->preparedPayload(['uuid' => 'job-2', 'displayName' => 'TestJob']);
        $this->repo->pushed('sqs', 'orders', $payload);

        $this->repo->reserved('sqs', 'orders', $payload);

        $hash = $this->redis->hgetall('sunset:job:job-2');
        $this->assertSame('reserved', $hash['status']);
        $this->assertNotEmpty($hash['reserved_at']);
    }

    public function test_released_sets_pending_status_and_records_delay(): void
    {
        $payload = $this->preparedPayload(['uuid' => 'job-3', 'displayName' => 'TestJob']);
        $this->repo->pushed('sqs', 'orders', $payload);

        $this->repo->released('sqs', 'orders', $payload, 30);

        $hash = $this->redis->hgetall('sunset:job:job-3');
        $this->assertSame('pending', $hash['status']);
        $this->assertSame('30', $hash['delay']);
    }

    public function test_completed_marks_status_and_timestamps(): void
    {
        $payload = $this->preparedPayload(['uuid' => 'job-4', 'displayName' => 'TestJob']);
        $this->repo->pushed('sqs', 'orders', $payload);
        $this->repo->reserved('sqs', 'orders', $payload);

        $this->repo->completed($payload);

        $hash = $this->redis->hgetall('sunset:job:job-4');
        $this->assertSame('completed', $hash['status']);
        $this->assertNotEmpty($hash['completed_at']);
        $this->assertSame(1, $this->redis->zcard('sunset:completed_jobs'));
        $ttl = $this->redis->ttl('sunset:job:job-4');
        $this->assertGreaterThan(0, $ttl, 'completed() should set a TTL on the job hash');
    }

    public function test_completed_silenced_routes_to_silenced_index(): void
    {
        $payload = $this->preparedPayload(['uuid' => 'job-5', 'displayName' => 'TestJob']);
        $this->repo->pushed('sqs', 'orders', $payload);

        $this->repo->completed($payload, silenced: true);

        $this->assertSame(1, $this->redis->zcard('sunset:silenced_jobs'));
        $this->assertSame(0, $this->redis->zcard('sunset:completed_jobs'));
    }

    public function test_remember_adds_to_monitored_index(): void
    {
        $payload = $this->preparedPayload(['uuid' => 'job-6', 'displayName' => 'TestJob']);

        $this->repo->remember('sqs', 'orders', $payload);

        $this->assertSame(1, $this->redis->zcard('sunset:monitored_jobs'));
        $hash = $this->redis->hgetall('sunset:job:job-6');
        $this->assertSame('job-6', $hash['id']);
        $this->assertSame('completed', $hash['status'], 'remember() records monitored jobs as completed');
    }

    public function test_migrated_replays_pushed_for_each_payload(): void
    {
        $a = $this->preparedPayload(['uuid' => 'a', 'displayName' => 'JobA']);
        $b = $this->preparedPayload(['uuid' => 'b', 'displayName' => 'JobB']);

        $this->repo->migrated('redis', 'default', new Collection([$a, $b]));

        $this->assertSame(2, $this->redis->zcard('sunset:recent_jobs'));
        $this->assertSame(2, $this->redis->zcard('sunset:pending_jobs'));
    }

    public function test_count_methods_match_zset_cardinality(): void
    {
        $this->repo->pushed('sqs', 'q', $this->preparedPayload(['uuid' => 'a']));
        $this->repo->pushed('sqs', 'q', $this->preparedPayload(['uuid' => 'b']));
        $this->repo->completed($this->preparedPayload(['uuid' => 'a']));

        $this->assertSame(2, $this->repo->countRecent());
        $this->assertSame(1, $this->repo->countPending());
        $this->assertSame(1, $this->repo->countCompleted());
        $this->assertSame(0, $this->repo->countSilenced());
        $this->assertSame(2, $this->repo->totalRecent());
    }

    public function test_get_recent_returns_jobs_in_reverse_chronological_order(): void
    {
        $this->repo->pushed('sqs', 'q', $this->preparedPayload(['uuid' => 'first']));
        usleep(1000);
        $this->repo->pushed('sqs', 'q', $this->preparedPayload(['uuid' => 'second']));

        $recent = $this->repo->getRecent();

        $this->assertCount(2, $recent);
        $this->assertSame('second', $recent->first()->id);
    }

    public function test_get_pending_filters_out_completed(): void
    {
        $a = $this->preparedPayload(['uuid' => 'a']);
        $b = $this->preparedPayload(['uuid' => 'b']);
        $this->repo->pushed('sqs', 'q', $a);
        $this->repo->pushed('sqs', 'q', $b);
        $this->repo->completed($a);

        $pending = $this->repo->getPending();
        $this->assertCount(1, $pending);
        $this->assertSame('b', $pending->first()->id);
    }

    public function test_get_jobs_returns_hashes_keyed_by_id(): void
    {
        $this->repo->pushed('sqs', 'q', $this->preparedPayload(['uuid' => 'a']));
        $this->repo->pushed('sqs', 'q', $this->preparedPayload(['uuid' => 'b']));

        $jobs = $this->repo->getJobs(['a', 'b']);

        $this->assertCount(2, $jobs);
        $ids = $jobs->pluck('id')->all();
        $this->assertContains('a', $ids);
        $this->assertContains('b', $ids);
    }

    public function test_get_jobs_skips_missing_ids(): void
    {
        $this->repo->pushed('sqs', 'q', $this->preparedPayload(['uuid' => 'a']));
        $jobs = $this->repo->getJobs(['a', 'does-not-exist']);
        $this->assertCount(1, $jobs);
    }

    public function test_trim_recent_jobs_removes_expired_entries(): void
    {
        // Add a job with a score far in the past (older than the trim window).
        // Cast to float: phpredis 6 treats an int score as option flags, not a score value.
        $longAgo = (float) ((int) (microtime(true) * 1000) - (60 * 60 * 1000 * 100)); // 100 hrs ago in ms
        $this->redis->zadd('sunset:recent_jobs', $longAgo, 'old-job');
        $this->redis->zadd('sunset:recent_jobs', (float) ((int) (microtime(true) * 1000)), 'new-job');

        $this->repo->trimRecentJobs();

        $remaining = $this->redis->zrange('sunset:recent_jobs', 0, -1);
        $this->assertNotContains('old-job', $remaining);
        $this->assertContains('new-job', $remaining);
    }

    public function test_delete_monitored_removes_index_entries_and_hashes(): void
    {
        $this->repo->remember('sqs', 'q', $this->preparedPayload(['uuid' => 'a']));
        $this->repo->remember('sqs', 'q', $this->preparedPayload(['uuid' => 'b']));

        $this->repo->deleteMonitored(['a']);

        $this->assertSame(1, $this->redis->zcard('sunset:monitored_jobs'));
        $this->assertSame([], $this->redis->hgetall('sunset:job:a'));
    }

    public function test_store_retry_reference_writes_retried_by(): void
    {
        $this->repo->pushed('sqs', 'q', $this->preparedPayload(['uuid' => 'original']));

        $this->repo->storeRetryReference('original', 'retry-id-1');

        $hash = $this->redis->hgetall('sunset:job:original');
        $this->assertStringContainsString('retry-id-1', $hash['retried_by'] ?? '');
    }

    private function preparedPayload(array $decoded): JobPayload
    {
        $decoded += ['displayName' => 'TestJob', 'data' => [], 'tags' => []];
        return new JobPayload(json_encode($decoded));
    }
}
