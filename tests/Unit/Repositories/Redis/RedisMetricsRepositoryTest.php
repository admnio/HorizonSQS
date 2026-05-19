<?php

namespace Admnio\Sunset\Tests\Unit\Repositories\Redis;

use Admnio\Sunset\Repositories\Redis\RedisMetricsRepository;
use Admnio\Sunset\Tests\TestCase;
use Illuminate\Contracts\Redis\Factory as RedisFactory;

class RedisMetricsRepositoryTest extends TestCase
{
    private RedisMetricsRepository $repo;
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
        $this->repo = new RedisMetricsRepository($factory);
    }

    public function test_increment_throughput_records_job_and_queue_runtime_and_counts(): void
    {
        $this->repo->incrementThroughput('App\\Jobs\\SendEmail', 'default', 1.25);
        $this->repo->incrementThroughput('App\\Jobs\\SendEmail', 'default', 0.75);

        $this->assertSame(2, $this->repo->throughputForJob('App\\Jobs\\SendEmail'));
        $this->assertSame(2, $this->repo->throughputForQueue('default'));
        $this->assertEqualsWithDelta(1.0, $this->repo->runtimeForJob('App\\Jobs\\SendEmail'), 0.01);
        $this->assertEqualsWithDelta(1.0, $this->repo->runtimeForQueue('default'), 0.01);
    }

    public function test_jobs_and_queues_return_measured_lists(): void
    {
        $this->repo->incrementThroughput('JobA', 'q1', 1.0);
        $this->repo->incrementThroughput('JobB', 'q2', 1.0);

        $this->assertEqualsCanonicalizing(['JobA', 'JobB'], $this->repo->jobs());
        $this->assertEqualsCanonicalizing(['q1', 'q2'], $this->repo->queues());
    }

    public function test_snapshot_writes_a_snapshot_per_measured_job_and_queue(): void
    {
        $this->repo->incrementThroughput('JobA', 'q1', 1.5);
        $this->repo->snapshot();

        $jobSnaps = $this->repo->snapshotsForJob('JobA');
        $queueSnaps = $this->repo->snapshotsForQueue('q1');

        $this->assertNotEmpty($jobSnaps);
        $this->assertNotEmpty($queueSnaps);
        $this->assertArrayHasKey('time', $jobSnaps[0]);
        $this->assertArrayHasKey('throughput', $jobSnaps[0]);

        // Snapshot content should reflect the data pushed before snapshot.
        $this->assertSame(1, $jobSnaps[0]['throughput']);
        $this->assertEqualsWithDelta(1.5, $jobSnaps[0]['runtime'], 0.01);

        // After snapshot, interval counters reset to zero.
        $this->assertSame(0, $this->repo->throughputForJob('JobA'));
    }

    public function test_latest_snapshot_at_returns_zero_before_any_snapshot(): void
    {
        $this->assertSame(0, $this->repo->latestSnapshotAt());
    }

    public function test_latest_snapshot_at_advances_after_snapshot(): void
    {
        $this->repo->incrementThroughput('JobA', 'q1', 1.0);
        $before = time();
        $this->repo->snapshot();

        $this->assertGreaterThanOrEqual($before, $this->repo->latestSnapshotAt());
    }

    public function test_forget_job_and_queue_remove_measurement_entries(): void
    {
        $this->repo->incrementThroughput('JobA', 'q1', 1.0);

        $this->repo->forgetJob('JobA');
        $this->repo->forgetQueue('q1');

        $this->assertSame(0, $this->repo->throughputForJob('JobA'));
        $this->assertSame(0, $this->repo->throughputForQueue('q1'));
        $this->assertNotContains('JobA', $this->repo->jobs());
        $this->assertNotContains('q1', $this->repo->queues());
    }

    public function test_acquire_wait_times_returns_array_keyed_by_connection_queue(): void
    {
        $this->redis->hset('sunset:wait', 'sqs:default', '15');
        $this->redis->hset('sunset:wait', 'redis:high', '30');

        $waits = $this->repo->acquireWaitTimes();

        $this->assertSame('15', $waits['sqs:default'] ?? null);
        $this->assertSame('30', $waits['redis:high'] ?? null);
    }

    public function test_acquire_wait_time_lock_is_idempotent_within_ttl(): void
    {
        $first = $this->repo->acquireWaitTimeLock(60);
        $second = $this->repo->acquireWaitTimeLock(60);

        $this->assertTrue($first);
        $this->assertFalse($second);

        $this->redis->del('sunset:wait-time-lock');
    }
}
