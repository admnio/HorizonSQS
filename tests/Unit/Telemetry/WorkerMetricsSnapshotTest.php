<?php

namespace Admnio\Sunset\Tests\Unit\Telemetry;

use Admnio\Sunset\Telemetry\WorkerMetricsSnapshot;
use Admnio\Sunset\Tests\TestCase;

class WorkerMetricsSnapshotTest extends TestCase
{
    public function test_constructor_assigns_all_fields(): void
    {
        $snapshot = new WorkerMetricsSnapshot(
            pid: 4242,
            supervisor: 'supervisor-1',
            connection: 'redis',
            queues: ['default', 'geocode'],
            startedAt: 1_700_000_000,
            rssBytes: 33_554_432,
            cpuPct: 42.5,
            jobsProcessed: 17,
            lastReportAt: 1_700_000_050,
        );

        $this->assertSame(4242, $snapshot->pid);
        $this->assertSame('supervisor-1', $snapshot->supervisor);
        $this->assertSame('redis', $snapshot->connection);
        $this->assertSame(['default', 'geocode'], $snapshot->queues);
        $this->assertSame(1_700_000_000, $snapshot->startedAt);
        $this->assertSame(33_554_432, $snapshot->rssBytes);
        $this->assertSame(42.5, $snapshot->cpuPct);
        $this->assertSame(17, $snapshot->jobsProcessed);
        $this->assertSame(1_700_000_050, $snapshot->lastReportAt);
    }

    public function test_constructor_allows_null_supervisor_connection_queues_and_cpu_pct(): void
    {
        $snapshot = new WorkerMetricsSnapshot(
            pid: 1,
            supervisor: null,
            connection: null,
            queues: null,
            startedAt: 0,
            rssBytes: 1024,
            cpuPct: null,
            jobsProcessed: 0,
            lastReportAt: 0,
        );

        $this->assertNull($snapshot->supervisor);
        $this->assertNull($snapshot->connection);
        $this->assertNull($snapshot->queues);
        $this->assertNull($snapshot->cpuPct);
    }

    public function test_to_array_returns_snake_case_keys(): void
    {
        $snapshot = new WorkerMetricsSnapshot(
            pid: 4242,
            supervisor: 'sup',
            connection: 'redis',
            queues: ['a', 'b'],
            startedAt: 1_700_000_000,
            rssBytes: 1024,
            cpuPct: 12.5,
            jobsProcessed: 3,
            lastReportAt: 1_700_000_100,
        );

        $array = $snapshot->toArray();

        $this->assertSame([
            'pid' => 4242,
            'supervisor' => 'sup',
            'connection' => 'redis',
            'queues' => ['a', 'b'],
            'started_at' => 1_700_000_000,
            'rss_bytes' => 1024,
            'cpu_pct' => 12.5,
            'jobs_processed' => 3,
            'last_report_at' => 1_700_000_100,
        ], $array);
    }

    public function test_to_array_preserves_null_cpu_pct_and_null_queues(): void
    {
        $snapshot = new WorkerMetricsSnapshot(
            pid: 1,
            supervisor: null,
            connection: null,
            queues: null,
            startedAt: 1,
            rssBytes: 2,
            cpuPct: null,
            jobsProcessed: 0,
            lastReportAt: 3,
        );

        $array = $snapshot->toArray();

        $this->assertNull($array['cpu_pct']);
        $this->assertNull($array['queues']);
        $this->assertNull($array['supervisor']);
        $this->assertNull($array['connection']);
    }

    public function test_from_array_round_trips(): void
    {
        $original = new WorkerMetricsSnapshot(
            pid: 4242,
            supervisor: 'sup',
            connection: 'redis',
            queues: ['default', 'geocode'],
            startedAt: 1_700_000_000,
            rssBytes: 1024,
            cpuPct: 42.5,
            jobsProcessed: 7,
            lastReportAt: 1_700_000_100,
        );

        $roundTripped = WorkerMetricsSnapshot::fromArray($original->toArray());

        $this->assertEquals($original, $roundTripped);
    }

    public function test_from_array_round_trips_with_nulls(): void
    {
        $original = new WorkerMetricsSnapshot(
            pid: 1,
            supervisor: null,
            connection: null,
            queues: null,
            startedAt: 0,
            rssBytes: 1024,
            cpuPct: null,
            jobsProcessed: 0,
            lastReportAt: 0,
        );

        $roundTripped = WorkerMetricsSnapshot::fromArray($original->toArray());

        $this->assertEquals($original, $roundTripped);
        $this->assertNull($roundTripped->cpuPct);
        $this->assertNull($roundTripped->queues);
    }

    public function test_from_array_coerces_numeric_strings_to_proper_types(): void
    {
        // Redis HGETALL returns everything as strings; fromArray must coerce.
        $snapshot = WorkerMetricsSnapshot::fromArray([
            'pid' => '4242',
            'supervisor' => 'sup',
            'connection' => 'redis',
            'queues' => ['default'],
            'started_at' => '1700000000',
            'rss_bytes' => '1024',
            'cpu_pct' => '42.5',
            'jobs_processed' => '7',
            'last_report_at' => '1700000100',
        ]);

        $this->assertSame(4242, $snapshot->pid);
        $this->assertSame(1_700_000_000, $snapshot->startedAt);
        $this->assertSame(1024, $snapshot->rssBytes);
        $this->assertSame(42.5, $snapshot->cpuPct);
        $this->assertSame(7, $snapshot->jobsProcessed);
        $this->assertSame(1_700_000_100, $snapshot->lastReportAt);
    }
}
