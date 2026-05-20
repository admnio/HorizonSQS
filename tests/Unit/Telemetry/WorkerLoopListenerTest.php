<?php

namespace Admnio\Sunset\Tests\Unit\Telemetry;

use Admnio\Sunset\Contracts\WorkerMetricsRepository;
use Admnio\Sunset\Telemetry\WorkerLoopListener;
use Admnio\Sunset\Telemetry\WorkerMetricsSampler;
use Admnio\Sunset\Telemetry\WorkerMetricsSnapshot;
use Admnio\Sunset\Tests\TestCase;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\Looping;
use Mockery;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

class WorkerLoopListenerTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_handle_looping_does_nothing_when_telemetry_disabled(): void
    {
        $repo = Mockery::mock(WorkerMetricsRepository::class);
        $repo->shouldNotReceive('record');

        $sampler = Mockery::mock(WorkerMetricsSampler::class);
        $sampler->shouldNotReceive('sample');

        $listener = new WorkerLoopListener(
            repository: $repo,
            logger: new NullLogger(),
            enabled: false,
            intervalSeconds: 5,
            samplerOverride: $sampler,
        );

        $listener->handleLooping(new Looping('redis', 'default'));

        // Mockery expectations enforce the "no calls" assertion. The explicit
        // assertion below keeps PHPUnit from flagging the test as risky.
        $this->assertTrue(true);
    }

    public function test_handle_job_processed_does_nothing_when_telemetry_disabled(): void
    {
        $repo = Mockery::mock(WorkerMetricsRepository::class);
        $repo->shouldNotReceive('record');

        $sampler = Mockery::mock(WorkerMetricsSampler::class);
        $sampler->shouldNotReceive('recordJob');

        $listener = new WorkerLoopListener(
            repository: $repo,
            logger: new NullLogger(),
            enabled: false,
            intervalSeconds: 5,
            samplerOverride: $sampler,
        );

        $job = Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
        $listener->handleJobProcessed(new JobProcessed('redis', $job));

        $this->assertTrue(true);
    }

    public function test_handle_looping_skips_record_when_sampler_returns_null(): void
    {
        $repo = Mockery::mock(WorkerMetricsRepository::class);
        $repo->shouldNotReceive('record');

        $sampler = Mockery::mock(WorkerMetricsSampler::class);
        $sampler->shouldReceive('sample')->once()->andReturn(null);

        $listener = new WorkerLoopListener(
            repository: $repo,
            logger: new NullLogger(),
            enabled: true,
            intervalSeconds: 5,
            samplerOverride: $sampler,
        );

        $listener->handleLooping(new Looping('redis', 'default'));

        $this->assertTrue(true);
    }

    public function test_handle_looping_records_snapshot_when_sampler_returns_one(): void
    {
        $snapshot = $this->makeSnapshot();

        $sampler = Mockery::mock(WorkerMetricsSampler::class);
        $sampler->shouldReceive('sample')->once()->andReturn($snapshot);

        $repo = Mockery::mock(WorkerMetricsRepository::class);
        $repo->shouldReceive('record')->once()->with($snapshot);

        $listener = new WorkerLoopListener(
            repository: $repo,
            logger: new NullLogger(),
            enabled: true,
            intervalSeconds: 5,
            samplerOverride: $sampler,
        );

        $listener->handleLooping(new Looping('redis', 'default'));

        $this->assertTrue(true);
    }

    public function test_handle_looping_passes_connection_name_to_sampler(): void
    {
        $snapshot = $this->makeSnapshot();

        $sampler = Mockery::mock(WorkerMetricsSampler::class);
        $sampler->shouldReceive('sample')
            ->once()
            ->withArgs(function ($supervisor, $connection, $queues) {
                return $connection === 'sqs';
            })
            ->andReturn($snapshot);

        $repo = Mockery::mock(WorkerMetricsRepository::class);
        $repo->shouldReceive('record')->once()->with($snapshot);

        $listener = new WorkerLoopListener(
            repository: $repo,
            logger: new NullLogger(),
            enabled: true,
            intervalSeconds: 5,
            samplerOverride: $sampler,
        );

        $listener->handleLooping(new Looping('sqs', 'default'));

        $this->assertTrue(true);
    }

    public function test_handle_looping_swallows_repository_exception_and_logs_at_debug(): void
    {
        $snapshot = $this->makeSnapshot();

        $sampler = Mockery::mock(WorkerMetricsSampler::class);
        $sampler->shouldReceive('sample')->once()->andReturn($snapshot);

        $repo = Mockery::mock(WorkerMetricsRepository::class);
        $repo->shouldReceive('record')
            ->once()
            ->andThrow(new RuntimeException('Redis is gone'));

        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('debug')
            ->once()
            ->withArgs(function ($message, $context) {
                return is_string($message)
                    && str_contains($message, 'Sunset')
                    && isset($context['exception'])
                    && $context['exception'] instanceof RuntimeException;
            });

        $listener = new WorkerLoopListener(
            repository: $repo,
            logger: $logger,
            enabled: true,
            intervalSeconds: 5,
            samplerOverride: $sampler,
        );

        // Should NOT throw.
        $listener->handleLooping(new Looping('redis', 'default'));

        $this->assertTrue(true);
    }

    public function test_handle_job_processed_increments_sampler_jobs_counter(): void
    {
        $repo = Mockery::mock(WorkerMetricsRepository::class);
        $repo->shouldNotReceive('record');

        $sampler = Mockery::mock(WorkerMetricsSampler::class);
        $sampler->shouldReceive('recordJob')->once();

        $listener = new WorkerLoopListener(
            repository: $repo,
            logger: new NullLogger(),
            enabled: true,
            intervalSeconds: 5,
            samplerOverride: $sampler,
        );

        $job = Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
        $listener->handleJobProcessed(new JobProcessed('redis', $job));

        $this->assertTrue(true);
    }

    public function test_sampler_is_constructed_lazily_when_no_override_given(): void
    {
        // No override → the listener should build a real sampler on first event.
        // We verify by passing an enabled-true listener, firing a Looping, and
        // ensuring the repository's record() gets called with a snapshot whose
        // pid matches the current PHP process.
        $captured = null;

        $repo = Mockery::mock(WorkerMetricsRepository::class);
        $repo->shouldReceive('record')
            ->once()
            ->with(Mockery::on(function (WorkerMetricsSnapshot $snapshot) use (&$captured) {
                $captured = $snapshot;
                return true;
            }));

        $listener = new WorkerLoopListener(
            repository: $repo,
            logger: new NullLogger(),
            enabled: true,
            intervalSeconds: 5,
        );

        $listener->handleLooping(new Looping('redis', 'default'));

        $this->assertNotNull($captured);
        $this->assertSame(getmypid(), $captured->pid);
        $this->assertGreaterThan(0, $captured->rssBytes);
    }

    public function test_handle_job_processed_with_no_override_lazily_constructs_sampler(): void
    {
        // Just verifies it doesn't throw and the lazy construction path works
        // for the JobProcessed event too (the first event might be a
        // JobProcessed before any Looping fires).
        $repo = Mockery::mock(WorkerMetricsRepository::class);
        $repo->shouldNotReceive('record');

        $listener = new WorkerLoopListener(
            repository: $repo,
            logger: new NullLogger(),
            enabled: true,
            intervalSeconds: 5,
        );

        $job = Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
        $listener->handleJobProcessed(new JobProcessed('redis', $job));

        // No exception — pass.
        $this->assertTrue(true);
    }

    public function test_handle_looping_passes_event_queue_to_sampler(): void
    {
        $snapshot = $this->makeSnapshot();

        $sampler = Mockery::mock(WorkerMetricsSampler::class);
        $sampler->shouldReceive('sample')
            ->once()
            ->withArgs(function ($supervisor, $connection, $queues) {
                return $queues === ['high', 'default'];
            })
            ->andReturn($snapshot);

        $repo = Mockery::mock(WorkerMetricsRepository::class);
        $repo->shouldReceive('record')->once()->with($snapshot);

        $listener = new WorkerLoopListener(
            repository: $repo,
            logger: new NullLogger(),
            enabled: true,
            intervalSeconds: 5,
            samplerOverride: $sampler,
        );

        $listener->handleLooping(new Looping('redis', 'high,default'));

        $this->assertTrue(true);
    }

    public function test_handle_looping_parses_supervisor_from_argv(): void
    {
        $snapshot = $this->makeSnapshot();
        $originalArgv = $_SERVER['argv'] ?? null;
        $_SERVER['argv'] = ['artisan', 'sunset:worker', 'redis', '--supervisor=master-1:web', '--queue=default'];

        try {
            $sampler = Mockery::mock(WorkerMetricsSampler::class);
            $sampler->shouldReceive('sample')
                ->once()
                ->withArgs(function ($supervisor, $connection, $queues) {
                    return $supervisor === 'master-1:web';
                })
                ->andReturn($snapshot);

            $repo = Mockery::mock(WorkerMetricsRepository::class);
            $repo->shouldReceive('record')->once()->with($snapshot);

            $listener = new WorkerLoopListener(
                repository: $repo,
                logger: new NullLogger(),
                enabled: true,
                intervalSeconds: 5,
                samplerOverride: $sampler,
            );

            $listener->handleLooping(new Looping('redis', 'default'));

            $this->assertTrue(true);
        } finally {
            if ($originalArgv === null) {
                unset($_SERVER['argv']);
            } else {
                $_SERVER['argv'] = $originalArgv;
            }
        }
    }

    private function makeSnapshot(): WorkerMetricsSnapshot
    {
        return new WorkerMetricsSnapshot(
            pid: 9999,
            supervisor: 'master-1:sup',
            connection: 'redis',
            queues: ['default'],
            startedAt: 1_700_000_000,
            rssBytes: 16_000_000,
            cpuPct: 5.0,
            jobsProcessed: 0,
            lastReportAt: 1_700_000_000,
        );
    }
}
