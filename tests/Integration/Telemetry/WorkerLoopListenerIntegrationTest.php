<?php

namespace Admnio\Sunset\Tests\Integration\Telemetry;

use Admnio\Sunset\Contracts\WorkerMetricsRepository;
use Admnio\Sunset\Telemetry\WorkerLoopListener;
use Admnio\Sunset\Telemetry\WorkerMetricsSnapshot;
use Admnio\Sunset\Tests\Integration\IntegrationTestCase;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Queue\Events\Looping;

/**
 * End-to-end check: firing the real Looping event on the wired-up dispatcher
 * causes the WorkerLoopListener to sample the current PHP process and write
 * a hash into Redis under sunset:worker_metrics:{pid}.
 */
class WorkerLoopListenerIntegrationTest extends IntegrationTestCase
{
    /** @var \Illuminate\Redis\Connections\Connection */
    private $redis;

    protected function setUp(): void
    {
        parent::setUp();

        $factory = $this->app->make(RedisFactory::class);
        $this->redis = $factory->connection('default');

        // FLUSHDB-equivalent: wipe any leftover sunset:* keys so this run is
        // isolated from earlier integration tests.
        foreach ($this->redis->keys('sunset:*') as $key) {
            $name = str_replace($this->redis->_prefix(''), '', $key);
            $this->redis->del($name);
        }
    }

    public function test_listener_writes_a_snapshot_for_the_current_pid_when_looping_fires(): void
    {
        // Telemetry is enabled by default in config. Sanity-check.
        $this->assertTrue((bool) config('sunset.telemetry.enabled'));

        // Resolve and assert the listener singleton is correctly bound.
        $listener = $this->app->make(WorkerLoopListener::class);
        $this->assertInstanceOf(WorkerLoopListener::class, $listener);

        // Fire a real Looping event through the dispatcher — the
        // SunsetServiceProvider should have subscribed the listener in boot().
        event(new Looping('redis', 'default'));

        $repo = $this->app->make(WorkerMetricsRepository::class);
        $snapshot = $repo->find(getmypid());

        $this->assertInstanceOf(WorkerMetricsSnapshot::class, $snapshot);
        $this->assertSame(getmypid(), $snapshot->pid);

        // Any reasonable PHP process consumes more than 1MB of RSS once
        // Laravel + Testbench are booted.
        $this->assertGreaterThan(1024 * 1024, $snapshot->rssBytes);

        // First sample → cpu_pct is always null (no previous reading to delta
        // against). The CPU-pct integration assertion is the only Windows-
        // specific concern, but on first sample it's null on all platforms, so
        // this assertion is portable.
        $this->assertNull($snapshot->cpuPct);

        // connection comes from the Looping event itself.
        $this->assertSame('redis', $snapshot->connection);
    }

    public function test_listener_is_a_singleton_so_subsequent_resolves_share_state(): void
    {
        $a = $this->app->make(WorkerLoopListener::class);
        $b = $this->app->make(WorkerLoopListener::class);

        $this->assertSame($a, $b);
    }

    public function test_job_processed_event_does_not_write_a_snapshot_on_its_own(): void
    {
        // Other Sunset listeners (CleanupExtendedPayload, TranslateJobProcessed,
        // ReleaseConcurrencySlots) are also subscribed to JobProcessed and will
        // poke the Job mock. shouldIgnoreMissing() returns a generic stub for
        // any unexpected method so we only have to wire up the one method that
        // actually returns a non-null value (the raw body, which the translator
        // JSON-decodes).
        $job = \Mockery::mock(\Illuminate\Contracts\Queue\Job::class)->shouldIgnoreMissing();
        $job->shouldReceive('getRawBody')->andReturn(json_encode(['uuid' => 'test']));

        event(new \Illuminate\Queue\Events\JobProcessed('redis', $job));

        $repo = $this->app->make(WorkerMetricsRepository::class);
        // JobProcessed only bumps the in-memory counter; it must not write to Redis.
        $this->assertNull($repo->find(getmypid()));

        \Mockery::close();
    }
}
