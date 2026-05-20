<?php

namespace Admnio\Sunset\Tests\Integration\Dashboard;

use Admnio\Sunset\Contracts\WorkerMetricsRepository;
use Admnio\Sunset\Facades\Sunset;
use Admnio\Sunset\Manager;
use Admnio\Sunset\Repositories\Redis\RedisWorkerMetricsRepository;
use Admnio\Sunset\Telemetry\WorkerMetricsSnapshot;
use Admnio\Sunset\Tests\Integration\IntegrationTestCase;
use Illuminate\Contracts\Redis\Factory as RedisFactory;

/**
 * Verifies the Supervisors dashboard page surfaces per-worker metrics + the
 * short sparkline series. Joins are by PID on the frontend, so the controller
 * exposes two top-level props (worker_metrics, worker_metric_series) that
 * both render branches (initial Inertia + ?refresh=1 JSON) must emit.
 */
class SupervisorsPageMetricsTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Manager::flushAuth();
        Sunset::auth(fn () => true);

        // Wipe the dedicated test redis DB (database 1 per TestCase env) so a
        // prior run's worker_metrics keys don't leak into the page payload.
        $this->app->make(RedisFactory::class)
            ->connection(config('sunset.redis_connection', 'default'))
            ->flushdb();
    }

    public function test_refresh_response_includes_worker_metrics_keyed_by_pid(): void
    {
        $this->seedTwoWorkersWithMetricsHistory();

        $response = $this->getJson('/sunset/supervisors?refresh=1');
        $response->assertStatus(200);

        $props = $response->json('props');
        $this->assertIsArray($props);

        $this->assertArrayHasKey('worker_metrics', $props);
        $metrics = $props['worker_metrics'];
        $this->assertIsArray($metrics);
        $this->assertArrayHasKey('1111', $metrics);
        $this->assertArrayHasKey('2222', $metrics);

        // Each entry is the snake_case shape from WorkerMetricsSnapshot::toArray().
        foreach (['1111', '2222'] as $pid) {
            $entry = $metrics[$pid];
            $this->assertIsArray($entry);
            foreach (['pid', 'supervisor', 'connection', 'queues', 'started_at', 'rss_bytes', 'cpu_pct', 'jobs_processed', 'last_report_at'] as $key) {
                $this->assertArrayHasKey($key, $entry, "Missing key '{$key}' for PID {$pid}");
            }
            $this->assertSame((int) $pid, $entry['pid']);
        }
    }

    public function test_refresh_response_includes_per_pid_sparkline_series_for_rss_and_cpu(): void
    {
        $this->seedTwoWorkersWithMetricsHistory();

        $response = $this->getJson('/sunset/supervisors?refresh=1');
        $response->assertStatus(200);

        $props = $response->json('props');
        $this->assertArrayHasKey('worker_metric_series', $props);
        $series = $props['worker_metric_series'];
        $this->assertIsArray($series);

        foreach (['1111', '2222'] as $pid) {
            $this->assertArrayHasKey($pid, $series);
            $this->assertArrayHasKey('rss', $series[$pid]);
            $this->assertArrayHasKey('cpu', $series[$pid]);
            $this->assertIsArray($series[$pid]['rss']);
            $this->assertIsArray($series[$pid]['cpu']);
            // Each is a list (sequentially indexed), not an object.
            $this->assertSame(array_values($series[$pid]['rss']), $series[$pid]['rss']);
            $this->assertSame(array_values($series[$pid]['cpu']), $series[$pid]['cpu']);
            // We seeded 5 points each.
            $this->assertCount(5, $series[$pid]['rss']);
            $this->assertCount(5, $series[$pid]['cpu']);
            // Point shape: {ts, value}.
            foreach ($series[$pid]['rss'] as $point) {
                $this->assertArrayHasKey('ts', $point);
                $this->assertArrayHasKey('value', $point);
            }
        }
    }

    public function test_initial_inertia_render_includes_the_same_metrics_props(): void
    {
        $this->seedTwoWorkersWithMetricsHistory();

        // Inertia request: send the X-Inertia header so we get the JSON envelope.
        $response = $this->withHeaders(['X-Inertia' => 'true'])->getJson('/sunset/supervisors');
        $response->assertStatus(200);

        $props = $response->json('props');
        $this->assertIsArray($props);
        $this->assertArrayHasKey('worker_metrics', $props);
        $this->assertArrayHasKey('worker_metric_series', $props);

        $this->assertArrayHasKey('1111', $props['worker_metrics']);
        $this->assertArrayHasKey('2222', $props['worker_metrics']);
        $this->assertArrayHasKey('1111', $props['worker_metric_series']);
        $this->assertArrayHasKey('2222', $props['worker_metric_series']);
    }

    public function test_props_are_empty_arrays_when_no_metrics_are_seeded(): void
    {
        $response = $this->getJson('/sunset/supervisors?refresh=1');
        $response->assertStatus(200);

        $props = $response->json('props');
        $this->assertSame([], $props['worker_metrics']);
        $this->assertSame([], $props['worker_metric_series']);
    }

    /**
     * Record five snapshots per PID at increasing timestamps so each series
     * has multiple points the controller will surface as the sparkline data.
     */
    private function seedTwoWorkersWithMetricsHistory(): void
    {
        /** @var RedisWorkerMetricsRepository $repo */
        $repo = $this->app->make(WorkerMetricsRepository::class);
        $this->assertInstanceOf(RedisWorkerMetricsRepository::class, $repo);

        $base = 1_700_000_000;
        foreach ([1111, 2222] as $pid) {
            for ($i = 0; $i < 5; $i++) {
                $repo->record(new WorkerMetricsSnapshot(
                    pid: $pid,
                    supervisor: 'master-1:sup-a',
                    connection: 'redis',
                    queues: ['default'],
                    startedAt: $base,
                    rssBytes: 10_000_000 + ($i * 1_000_000),
                    cpuPct: 5.0 + $i,
                    jobsProcessed: $i,
                    lastReportAt: $base + ($i * 5),
                ));
            }
        }
    }
}
