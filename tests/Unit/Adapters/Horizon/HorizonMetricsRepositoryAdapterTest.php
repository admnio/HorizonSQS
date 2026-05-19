<?php

namespace Admnio\Sunset\Tests\Unit\Adapters\Horizon;

use Admnio\Sunset\Adapters\Horizon\HorizonMetricsRepositoryAdapter;
use Admnio\Sunset\Contracts\MetricsRepository as SunsetMetricsRepo;
use Admnio\Sunset\Tests\TestCase;
use Mockery;

class HorizonMetricsRepositoryAdapterTest extends TestCase
{
    public function test_measured_jobs_and_queues_alias_sunset_methods(): void
    {
        $metrics = Mockery::mock(SunsetMetricsRepo::class);
        $metrics->shouldReceive('jobs')->andReturn(['JobA', 'JobB']);
        $metrics->shouldReceive('queues')->andReturn(['q1', 'q2']);

        $adapter = new HorizonMetricsRepositoryAdapter($metrics);

        $this->assertSame(['JobA', 'JobB'], $adapter->measuredJobs());
        $this->assertSame(['q1', 'q2'], $adapter->measuredQueues());
    }

    public function test_increment_job_then_increment_queue_invokes_sunset_increment(): void
    {
        $metrics = Mockery::mock(SunsetMetricsRepo::class);
        $metrics->shouldReceive('incrementThroughput')->once()
            ->with('JobA', 'q1', 2.5);

        $adapter = new HorizonMetricsRepositoryAdapter($metrics);
        $adapter->incrementJob('JobA', 2.5);
        $adapter->incrementQueue('q1', 2.5);
    }

    public function test_throughput_sums_per_job_values(): void
    {
        $metrics = Mockery::mock(SunsetMetricsRepo::class);
        $metrics->shouldReceive('jobs')->andReturn(['A', 'B']);
        $metrics->shouldReceive('throughputForJob')->with('A')->andReturn(3);
        $metrics->shouldReceive('throughputForJob')->with('B')->andReturn(4);

        $adapter = new HorizonMetricsRepositoryAdapter($metrics);
        $this->assertSame(7, $adapter->throughput());
    }

    public function test_queue_with_maximum_throughput_returns_max_queue(): void
    {
        $metrics = Mockery::mock(SunsetMetricsRepo::class);
        $metrics->shouldReceive('queues')->andReturn(['q1', 'q2', 'q3']);
        $metrics->shouldReceive('throughputForQueue')->with('q1')->andReturn(5);
        $metrics->shouldReceive('throughputForQueue')->with('q2')->andReturn(20);
        $metrics->shouldReceive('throughputForQueue')->with('q3')->andReturn(2);

        $adapter = new HorizonMetricsRepositoryAdapter($metrics);
        $this->assertSame('q2', $adapter->queueWithMaximumThroughput());
    }

    public function test_acquire_wait_time_monitor_lock_delegates(): void
    {
        $metrics = Mockery::mock(SunsetMetricsRepo::class);
        $metrics->shouldReceive('acquireWaitTimeLock')->with(60)->andReturn(true);

        $adapter = new HorizonMetricsRepositoryAdapter($metrics);
        $this->assertTrue($adapter->acquireWaitTimeMonitorLock());
    }

    public function test_forget_routes_to_job_or_queue(): void
    {
        $metrics = Mockery::mock(SunsetMetricsRepo::class);
        $metrics->shouldReceive('forgetJob')->with('App\\Jobs\\X')->once();
        $metrics->shouldReceive('forgetQueue')->with('App\\Jobs\\X')->once();

        $adapter = new HorizonMetricsRepositoryAdapter($metrics);
        $adapter->forget('App\\Jobs\\X'); // routes to both — idempotent
    }

    public function test_clear_iterates_jobs_and_queues(): void
    {
        $metrics = Mockery::mock(SunsetMetricsRepo::class);
        $metrics->shouldReceive('jobs')->andReturn(['A', 'B']);
        $metrics->shouldReceive('queues')->andReturn(['q1']);
        $metrics->shouldReceive('forgetJob')->with('A')->once();
        $metrics->shouldReceive('forgetJob')->with('B')->once();
        $metrics->shouldReceive('forgetQueue')->with('q1')->once();

        $adapter = new HorizonMetricsRepositoryAdapter($metrics);
        $adapter->clear();
    }

    public function test_snapshot_delegates(): void
    {
        $metrics = Mockery::mock(SunsetMetricsRepo::class);
        $metrics->shouldReceive('snapshot')->once();

        $adapter = new HorizonMetricsRepositoryAdapter($metrics);
        $adapter->snapshot();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
