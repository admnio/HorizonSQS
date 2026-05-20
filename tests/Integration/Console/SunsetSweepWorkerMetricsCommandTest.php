<?php

namespace Admnio\Sunset\Tests\Integration\Console;

use Admnio\Sunset\Repositories\Redis\RedisWorkerMetricsRepository;
use Admnio\Sunset\Telemetry\WorkerMetricsSnapshot;
use Admnio\Sunset\Tests\Integration\IntegrationTestCase;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Facades\Artisan;

/**
 * v1.1.0 — proves the sunset:sweep-worker-metrics command reconciles the
 * worker_metrics:pids set against TTL-expired snapshot hashes. The Looping
 * listener writes hashes with a 30s TTL but the series sorted sets get 600s;
 * if a worker dies without unregistering, its hash expires first while the
 * PID lingers in the set and orphan series keys remain. This sweep is the
 * scheduled safety net that prunes both.
 */
class SunsetSweepWorkerMetricsCommandTest extends IntegrationTestCase
{
    private RedisWorkerMetricsRepository $repo;

    /** @var \Illuminate\Redis\Connections\Connection */
    private $redis;

    protected function setUp(): void
    {
        parent::setUp();

        $factory = $this->app->make(RedisFactory::class);
        $this->redis = $factory->connection('default');

        // FLUSHDB-equivalent: wipe any leftover sunset:* keys from prior runs.
        foreach ($this->redis->keys('sunset:*') as $key) {
            $name = str_replace($this->redis->_prefix(''), '', $key);
            $this->redis->del($name);
        }

        $this->repo = new RedisWorkerMetricsRepository($factory);
    }

    public function test_removes_pids_whose_hash_has_expired_and_keeps_live_pids(): void
    {
        // Two live workers, two "crashed" workers whose hashes will be deleted
        // to simulate TTL expiry. The sweep should leave the live set intact
        // and SREM both dead pids out.
        $this->repo->record($this->makeSnapshot(pid: 1001));
        $this->repo->record($this->makeSnapshot(pid: 1002));
        $this->repo->record($this->makeSnapshot(pid: 1003));
        $this->repo->record($this->makeSnapshot(pid: 1004));

        // Simulate TTL expiry on two of them.
        $this->redis->del('sunset:worker_metrics:1002');
        $this->redis->del('sunset:worker_metrics:1004');

        $exit = Artisan::call('sunset:sweep-worker-metrics');
        $output = Artisan::output();

        $this->assertSame(0, $exit);

        // Live pids remain in the registry set.
        $this->assertTrue((bool) $this->redis->sismember('sunset:worker_metrics:pids', 1001));
        $this->assertTrue((bool) $this->redis->sismember('sunset:worker_metrics:pids', 1003));

        // Dead pids were SREM'd.
        $this->assertFalse((bool) $this->redis->sismember('sunset:worker_metrics:pids', 1002));
        $this->assertFalse((bool) $this->redis->sismember('sunset:worker_metrics:pids', 1004));

        $this->assertStringContainsString('Swept 2 stale worker-metrics entries', $output);
    }

    public function test_deletes_orphan_series_sorted_sets_for_swept_pids(): void
    {
        $this->repo->record($this->makeSnapshot(pid: 2001));
        $this->repo->record($this->makeSnapshot(pid: 2002));

        // Sanity: series exist before the sweep.
        $this->assertSame(1, (int) $this->redis->zcard('sunset:worker_metrics:2001:series:rss'));
        $this->assertSame(1, (int) $this->redis->zcard('sunset:worker_metrics:2001:series:cpu'));

        // Crash 2001 (delete its hash; series still hang around with 600s TTL).
        $this->redis->del('sunset:worker_metrics:2001');

        Artisan::call('sunset:sweep-worker-metrics');

        // Series for the swept pid are gone.
        $this->assertSame(0, (int) $this->redis->zcard('sunset:worker_metrics:2001:series:rss'));
        $this->assertSame(0, (int) $this->redis->zcard('sunset:worker_metrics:2001:series:cpu'));

        // Series for the live pid remain.
        $this->assertSame(1, (int) $this->redis->zcard('sunset:worker_metrics:2002:series:rss'));
        $this->assertSame(1, (int) $this->redis->zcard('sunset:worker_metrics:2002:series:cpu'));
    }

    public function test_empty_pids_set_is_a_no_op_exiting_zero(): void
    {
        $exit = Artisan::call('sunset:sweep-worker-metrics');
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Swept 0 stale worker-metrics entries', $output);
    }

    public function test_all_live_pids_reports_zero_sweeps(): void
    {
        $this->repo->record($this->makeSnapshot(pid: 3001));
        $this->repo->record($this->makeSnapshot(pid: 3002));

        $exit = Artisan::call('sunset:sweep-worker-metrics');
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Swept 0 stale worker-metrics entries', $output);

        // Both still registered.
        $this->assertTrue((bool) $this->redis->sismember('sunset:worker_metrics:pids', 3001));
        $this->assertTrue((bool) $this->redis->sismember('sunset:worker_metrics:pids', 3002));
    }

    private function makeSnapshot(
        int $pid = 1,
        ?string $supervisor = 'master-1:sup',
        ?string $connection = 'redis',
        ?array $queues = ['default'],
        int $startedAt = 1_699_999_900,
        int $rssBytes = 10_000_000,
        ?float $cpuPct = 5.0,
        int $jobsProcessed = 0,
        ?int $lastReportAt = null,
    ): WorkerMetricsSnapshot {
        return new WorkerMetricsSnapshot(
            pid: $pid,
            supervisor: $supervisor,
            connection: $connection,
            queues: $queues,
            startedAt: $startedAt,
            rssBytes: $rssBytes,
            cpuPct: $cpuPct,
            jobsProcessed: $jobsProcessed,
            lastReportAt: $lastReportAt ?? $startedAt,
        );
    }
}
