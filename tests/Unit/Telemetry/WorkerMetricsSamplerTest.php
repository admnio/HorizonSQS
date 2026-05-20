<?php

namespace Admnio\Sunset\Tests\Unit\Telemetry;

use Admnio\Sunset\Telemetry\WorkerMetricsSampler;
use Admnio\Sunset\Telemetry\WorkerMetricsSnapshot;
use Admnio\Sunset\Tests\TestCase;

class WorkerMetricsSamplerTest extends TestCase
{
    public function test_first_sample_returns_snapshot_with_null_cpu_pct(): void
    {
        $wall = 1000.0;
        $rss = 16 * 1024 * 1024;
        $pid = 4242;

        $sampler = $this->makeSampler($wall, 0.0, $rss, $pid, intervalSeconds: 5);

        $snapshot = $sampler->sample();

        $this->assertInstanceOf(WorkerMetricsSnapshot::class, $snapshot);
        $this->assertNull($snapshot->cpuPct);
        $this->assertSame($pid, $snapshot->pid);
        $this->assertSame($rss, $snapshot->rssBytes);
        $this->assertSame((int) $wall, $snapshot->lastReportAt);
        $this->assertSame((int) $wall, $snapshot->startedAt);
        $this->assertSame(0, $snapshot->jobsProcessed);
    }

    public function test_sample_returns_null_when_called_before_interval_elapsed(): void
    {
        $wall = 1000.0;
        $cpu = 0.0;
        $rss = 16 * 1024 * 1024;
        $pid = 4242;

        $sampler = new WorkerMetricsSampler(
            intervalSeconds: 5,
            clock: function () use (&$wall) {
                return $wall;
            },
            resourceUsage: function () use (&$cpu, &$rss, &$pid) {
                return ['user_sec' => $cpu * 0.7, 'sys_sec' => $cpu * 0.3, 'rss_bytes' => $rss, 'pid' => $pid];
            },
        );

        $this->assertNotNull($sampler->sample());

        // Two seconds later — below the 5s interval.
        $wall += 2.0;
        $this->assertNull($sampler->sample());

        // Still throttled at 4.9s.
        $wall += 2.9;
        $this->assertNull($sampler->sample());
    }

    public function test_second_sample_after_interval_computes_cpu_pct_delta(): void
    {
        $wall = 1000.0;
        $cpu = 0.0;
        $rss = 16 * 1024 * 1024;
        $pid = 4242;

        $sampler = new WorkerMetricsSampler(
            intervalSeconds: 5,
            clock: function () use (&$wall) {
                return $wall;
            },
            resourceUsage: function () use (&$cpu, &$rss, &$pid) {
                return ['user_sec' => $cpu * 0.7, 'sys_sec' => $cpu * 0.3, 'rss_bytes' => $rss, 'pid' => $pid];
            },
        );

        $first = $sampler->sample();
        $this->assertNotNull($first);
        $this->assertNull($first->cpuPct);

        // Burn 3.5 cpu seconds over 7 wall seconds = 50%.
        $wall += 7.0;
        $cpu = 3.5;

        $second = $sampler->sample();

        $this->assertNotNull($second);
        $this->assertNotNull($second->cpuPct);
        $this->assertEqualsWithDelta(50.0, $second->cpuPct, 0.001);
    }

    public function test_cpu_pct_stays_null_when_two_consecutive_samples_have_zero_cpu_delta(): void
    {
        // Simulates Windows: getrusage() reports ru_utime=0 / ru_stime=0.
        $wall = 1000.0;
        $rss = 8 * 1024 * 1024;
        $pid = 1234;

        $sampler = new WorkerMetricsSampler(
            intervalSeconds: 5,
            clock: function () use (&$wall) {
                return $wall;
            },
            resourceUsage: function () use (&$rss, &$pid) {
                return ['user_sec' => 0.0, 'sys_sec' => 0.0, 'rss_bytes' => $rss, 'pid' => $pid];
            },
        );

        $first = $sampler->sample();
        $this->assertNull($first->cpuPct);

        // Second sample — first time we see a delta == 0; per spec this counts
        // as the first consecutive zero, but still emits a 0.0 reading.
        $wall += 5.0;
        $second = $sampler->sample();
        $this->assertNotNull($second);
        $this->assertSame(0.0, $second->cpuPct);

        // Third sample — second consecutive zero CPU delta; cpu_pct becomes null.
        $wall += 5.0;
        $third = $sampler->sample();
        $this->assertNotNull($third);
        $this->assertNull($third->cpuPct);

        // Fourth sample — still zero, still null.
        $wall += 5.0;
        $fourth = $sampler->sample();
        $this->assertNull($fourth->cpuPct);
    }

    public function test_record_job_increments_jobs_processed_counter(): void
    {
        $wall = 1000.0;
        $sampler = $this->makeSampler($wall, 0.0, 1024, 42, intervalSeconds: 5);

        $sampler->recordJob();
        $sampler->recordJob();
        $sampler->recordJob();

        $snapshot = $sampler->sample();

        $this->assertSame(3, $snapshot->jobsProcessed);
    }

    public function test_started_at_is_captured_on_first_sample_and_preserved(): void
    {
        $wall = 1000.0;
        $cpu = 0.0;
        $rss = 1024;
        $pid = 99;

        $sampler = new WorkerMetricsSampler(
            intervalSeconds: 5,
            clock: function () use (&$wall) {
                return $wall;
            },
            resourceUsage: function () use (&$cpu, &$rss, &$pid) {
                return ['user_sec' => $cpu * 0.7, 'sys_sec' => $cpu * 0.3, 'rss_bytes' => $rss, 'pid' => $pid];
            },
        );

        $first = $sampler->sample();
        $this->assertSame(1000, $first->startedAt);

        $wall += 10.0;
        $cpu = 1.0;
        $second = $sampler->sample();
        $this->assertSame(1000, $second->startedAt);
        $this->assertSame(1010, $second->lastReportAt);

        $wall += 600.0;
        $cpu = 2.0;
        $third = $sampler->sample();
        $this->assertSame(1000, $third->startedAt);
    }

    public function test_sample_passes_through_supervisor_connection_and_queues(): void
    {
        $wall = 1000.0;
        $sampler = $this->makeSampler($wall, 0.0, 1024, 7, intervalSeconds: 5);

        $snapshot = $sampler->sample(
            supervisor: 'supervisor-1',
            connection: 'redis',
            queues: ['default', 'geocode'],
        );

        $this->assertSame('supervisor-1', $snapshot->supervisor);
        $this->assertSame('redis', $snapshot->connection);
        $this->assertSame(['default', 'geocode'], $snapshot->queues);
    }

    public function test_cpu_pct_recovers_to_non_null_after_zero_run_when_cpu_delta_returns(): void
    {
        $wall = 1000.0;
        $userSec = 0.0;
        $sysSec = 0.0;
        $rss = 1024;
        $pid = 1;

        $sampler = new WorkerMetricsSampler(
            intervalSeconds: 5,
            clock: function () use (&$wall) {
                return $wall;
            },
            resourceUsage: function () use (&$userSec, &$sysSec, &$rss, &$pid) {
                return ['user_sec' => $userSec, 'sys_sec' => $sysSec, 'rss_bytes' => $rss, 'pid' => $pid];
            },
        );

        // First sample: cpu_pct null.
        $sampler->sample();

        // Two consecutive zero-CPU samples.
        $wall += 5.0;
        $sampler->sample();
        $wall += 5.0;
        $third = $sampler->sample();
        $this->assertNull($third->cpuPct);

        // Now CPU advances — should report a real number again.
        $wall += 5.0;
        $userSec = 2.5;
        $sysSec = 0.0;
        $fourth = $sampler->sample();

        $this->assertNotNull($fourth->cpuPct);
        $this->assertEqualsWithDelta(50.0, $fourth->cpuPct, 0.1);
    }

    private function makeSampler(
        float &$wall,
        float $cpuSeconds,
        int $rss,
        int $pid,
        int $intervalSeconds = 5,
    ): WorkerMetricsSampler {
        return new WorkerMetricsSampler(
            intervalSeconds: $intervalSeconds,
            clock: function () use (&$wall) {
                return $wall;
            },
            resourceUsage: function () use ($cpuSeconds, $rss, $pid) {
                return [
                    'user_sec' => $cpuSeconds * 0.7,
                    'sys_sec' => $cpuSeconds * 0.3,
                    'rss_bytes' => $rss,
                    'pid' => $pid,
                ];
            },
        );
    }
}
