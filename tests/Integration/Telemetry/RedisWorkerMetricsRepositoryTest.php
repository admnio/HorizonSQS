<?php

namespace Admnio\Sunset\Tests\Integration\Telemetry;

use Admnio\Sunset\Repositories\Redis\RedisWorkerMetricsRepository;
use Admnio\Sunset\Telemetry\WorkerMetricsSnapshot;
use Admnio\Sunset\Tests\Integration\IntegrationTestCase;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use InvalidArgumentException;

class RedisWorkerMetricsRepositoryTest extends IntegrationTestCase
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

        // Pin the cap to a small number for easier assertions.
        config(['sunset.telemetry.series_points' => 5]);

        $this->repo = new RedisWorkerMetricsRepository($factory);
    }

    public function test_record_writes_hash_both_series_and_adds_pid_to_set(): void
    {
        $snapshot = $this->makeSnapshot(pid: 4242, rssBytes: 16_000_000, cpuPct: 12.5, lastReportAt: 1_700_000_000);

        $this->repo->record($snapshot);

        $hash = $this->redis->hgetall('sunset:worker_metrics:4242');
        $this->assertNotEmpty($hash);
        $this->assertSame('4242', (string) $hash['pid']);
        $this->assertSame('16000000', (string) $hash['rss_bytes']);
        $this->assertSame('1700000000', (string) $hash['last_report_at']);

        // PID is in the registry set.
        $this->assertTrue((bool) $this->redis->sismember('sunset:worker_metrics:pids', 4242));

        // RSS sorted set received its sample.
        $this->assertSame(1, (int) $this->redis->zcard('sunset:worker_metrics:4242:series:rss'));
        // CPU sorted set received its sample (cpuPct was not null).
        $this->assertSame(1, (int) $this->redis->zcard('sunset:worker_metrics:4242:series:cpu'));
    }

    public function test_hash_ttl_is_set_to_30_seconds(): void
    {
        $this->repo->record($this->makeSnapshot(pid: 100, lastReportAt: time()));

        $ttl = (int) $this->redis->ttl('sunset:worker_metrics:100');
        $this->assertGreaterThan(0, $ttl, 'hash should have a positive TTL');
        $this->assertLessThanOrEqual(30, $ttl);
        $this->assertGreaterThanOrEqual(25, $ttl, 'TTL should be approximately 30s on a fresh write');
    }

    public function test_find_returns_null_when_no_hash_exists(): void
    {
        $this->assertNull($this->repo->find(999_999));
    }

    public function test_find_returns_snapshot_for_recorded_pid(): void
    {
        $original = $this->makeSnapshot(
            pid: 555,
            supervisor: 'master-1:sup-a',
            connection: 'redis',
            queues: ['default', 'high'],
            startedAt: 1_700_000_000,
            rssBytes: 32_000_000,
            cpuPct: 17.5,
            jobsProcessed: 7,
            lastReportAt: 1_700_000_500,
        );

        $this->repo->record($original);

        $found = $this->repo->find(555);

        $this->assertInstanceOf(WorkerMetricsSnapshot::class, $found);
        $this->assertSame(555, $found->pid);
        $this->assertSame('master-1:sup-a', $found->supervisor);
        $this->assertSame('redis', $found->connection);
        $this->assertSame(['default', 'high'], $found->queues);
        $this->assertSame(1_700_000_000, $found->startedAt);
        $this->assertSame(32_000_000, $found->rssBytes);
        $this->assertEqualsWithDelta(17.5, $found->cpuPct, 0.001);
        $this->assertSame(7, $found->jobsProcessed);
        $this->assertSame(1_700_000_500, $found->lastReportAt);
    }

    public function test_all_returns_one_snapshot_per_recorded_pid(): void
    {
        $this->repo->record($this->makeSnapshot(pid: 1001));
        $this->repo->record($this->makeSnapshot(pid: 1002));
        $this->repo->record($this->makeSnapshot(pid: 1003));

        $all = $this->repo->all();

        $this->assertCount(3, $all);
        $pids = array_map(fn (WorkerMetricsSnapshot $s) => $s->pid, $all);
        sort($pids);
        $this->assertSame([1001, 1002, 1003], $pids);
    }

    public function test_all_reconciles_pids_whose_hash_expired(): void
    {
        $this->repo->record($this->makeSnapshot(pid: 2001));
        $this->repo->record($this->makeSnapshot(pid: 2002));

        // Simulate hash TTL expiry by deleting the hash directly.
        $this->redis->del('sunset:worker_metrics:2001');

        $all = $this->repo->all();

        $this->assertCount(1, $all);
        $this->assertSame(2002, $all[0]->pid);

        // The expired PID should have been removed from the registry set.
        $this->assertFalse((bool) $this->redis->sismember('sunset:worker_metrics:pids', 2001));
        $this->assertTrue((bool) $this->redis->sismember('sunset:worker_metrics:pids', 2002));
    }

    public function test_series_rss_returns_points_in_ascending_timestamp_order(): void
    {
        $base = 1_700_000_000;

        // Write three samples with strictly increasing timestamps.
        $this->repo->record($this->makeSnapshot(pid: 7000, rssBytes: 10_000_000, lastReportAt: $base + 0));
        $this->repo->record($this->makeSnapshot(pid: 7000, rssBytes: 11_000_000, lastReportAt: $base + 5));
        $this->repo->record($this->makeSnapshot(pid: 7000, rssBytes: 12_000_000, lastReportAt: $base + 10));

        $series = $this->repo->series(7000, 'rss');

        $this->assertCount(3, $series);
        $this->assertSame($base + 0,  $series[0]['ts']);
        $this->assertSame($base + 5,  $series[1]['ts']);
        $this->assertSame($base + 10, $series[2]['ts']);

        $this->assertSame(10_000_000, $series[0]['value']);
        $this->assertSame(11_000_000, $series[1]['value']);
        $this->assertSame(12_000_000, $series[2]['value']);

        // value field is int for rss.
        foreach ($series as $point) {
            $this->assertIsInt($point['value']);
        }
    }

    public function test_series_cpu_decodes_value_back_to_float(): void
    {
        $base = 1_700_000_000;

        $this->repo->record($this->makeSnapshot(pid: 7100, cpuPct: 12.34, lastReportAt: $base + 0));
        $this->repo->record($this->makeSnapshot(pid: 7100, cpuPct: 56.78, lastReportAt: $base + 5));

        $series = $this->repo->series(7100, 'cpu');

        $this->assertCount(2, $series);
        $this->assertSame($base + 0, $series[0]['ts']);
        $this->assertSame($base + 5, $series[1]['ts']);

        // Stored as (int) round(cpuPct * 100), so round-trip is to 2-decimal precision.
        $this->assertEqualsWithDelta(12.34, $series[0]['value'], 0.001);
        $this->assertEqualsWithDelta(56.78, $series[1]['value'], 0.001);

        $this->assertIsFloat($series[0]['value']);
        $this->assertIsFloat($series[1]['value']);
    }

    public function test_series_caps_to_series_points_after_excess_writes(): void
    {
        // Cap is pinned to 5 in setUp().
        $cap = 5;
        $base = 1_700_000_000;

        for ($i = 0; $i < $cap + 5; $i++) {
            $this->repo->record($this->makeSnapshot(
                pid: 8000,
                rssBytes: 1_000_000 + $i,
                lastReportAt: $base + $i,
            ));
        }

        $this->assertSame($cap, (int) $this->redis->zcard('sunset:worker_metrics:8000:series:rss'));

        // The oldest entries should have been evicted; surviving entries are the newest 5.
        $series = $this->repo->series(8000, 'rss');
        $this->assertCount($cap, $series);
        $tsValues = array_column($series, 'ts');
        $this->assertSame([$base + 5, $base + 6, $base + 7, $base + 8, $base + 9], $tsValues);
    }

    public function test_series_with_smaller_max_points_returns_newest_entries(): void
    {
        $base = 1_700_000_000;

        // Cap is 5; we'll write 5 samples then ask for the newest 2.
        for ($i = 0; $i < 5; $i++) {
            $this->repo->record($this->makeSnapshot(
                pid: 8100,
                rssBytes: 1_000_000 + $i,
                lastReportAt: $base + $i,
            ));
        }

        $series = $this->repo->series(8100, 'rss', maxPoints: 2);

        $this->assertCount(2, $series);
        // Returned in ascending order, but limited to the newest 2.
        $this->assertSame($base + 3, $series[0]['ts']);
        $this->assertSame($base + 4, $series[1]['ts']);
    }

    public function test_record_with_null_cpu_pct_skips_cpu_series_write(): void
    {
        $this->repo->record($this->makeSnapshot(
            pid: 9000,
            rssBytes: 5_000_000,
            cpuPct: null,
            lastReportAt: 1_700_000_000,
        ));

        $this->assertSame(1, (int) $this->redis->zcard('sunset:worker_metrics:9000:series:rss'));
        $this->assertSame(0, (int) $this->redis->zcard('sunset:worker_metrics:9000:series:cpu'));
    }

    public function test_series_throws_for_unknown_kind(): void
    {
        // Programmer error — surface it loudly instead of returning [].
        $this->expectException(InvalidArgumentException::class);

        $this->repo->series(1, 'foo');
    }

    public function test_series_returns_empty_array_when_no_series_exists(): void
    {
        $this->assertSame([], $this->repo->series(123_456, 'rss'));
        $this->assertSame([], $this->repo->series(123_456, 'cpu'));
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
