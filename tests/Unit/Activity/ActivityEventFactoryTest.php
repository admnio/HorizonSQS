<?php

namespace Admnio\Sunset\Tests\Unit\Activity;

use Admnio\Sunset\Activity\ActivityEvent;
use Admnio\Sunset\Activity\ActivityEventFactory;
use Admnio\Sunset\Events\JobCompleted;
use Admnio\Sunset\Events\JobFailed;
use Admnio\Sunset\Events\JobQueued;
use Admnio\Sunset\Events\JobRateLimited;
use Admnio\Sunset\Events\LongWaitDetected;
use Admnio\Sunset\Events\MasterSupervisorDeployed;
use Admnio\Sunset\Events\QueuePaused;
use Admnio\Sunset\Events\QueueResumed;
use Admnio\Sunset\Events\UnableToLaunchProcess;
use Admnio\Sunset\Events\WorkerProcessRestarting;
use Admnio\Sunset\JobPayload;
use Admnio\Sunset\Supervisor\WorkerProcess;
use Admnio\Sunset\Tests\TestCase;
use Mockery;
use Symfony\Component\Process\Process;

class ActivityEventFactoryTest extends TestCase
{
    private function factory(int $now = 1_700_000_000): ActivityEventFactory
    {
        return new ActivityEventFactory(fn () => $now);
    }

    public function test_returns_null_for_unknown_event(): void
    {
        $this->assertNull($this->factory()->from(new \stdClass()));
    }

    public function test_translates_job_failed(): void
    {
        $payload = new JobPayload(json_encode([
            'uuid' => 'j-1',
            'displayName' => 'App\\Jobs\\SendEmail',
            'exception_data' => json_encode([
                'class' => 'RuntimeException',
                'message' => 'boom',
            ]),
        ]));
        $event = new JobFailed('sqs', 'orders', $payload);

        $activity = $this->factory(1_700_000_111)->from($event);

        $this->assertInstanceOf(ActivityEvent::class, $activity);
        $this->assertSame(0, $activity->id);
        $this->assertSame('job_failed', $activity->type);
        $this->assertSame(1_700_000_111, $activity->occurredAt);
        $this->assertSame('j-1', $activity->payload['job_id']);
        $this->assertSame('App\\Jobs\\SendEmail', $activity->payload['job_class']);
        $this->assertSame('sqs', $activity->payload['connection']);
        $this->assertSame('orders', $activity->payload['queue']);
        $this->assertSame('RuntimeException', $activity->payload['exception_class']);
        $this->assertSame('boom', $activity->payload['exception_message']);
    }

    public function test_job_failed_handles_missing_exception_data(): void
    {
        $payload = new JobPayload(json_encode([
            'uuid' => 'j-2',
            'displayName' => 'App\\Jobs\\Foo',
        ]));
        $event = new JobFailed('redis', 'default', $payload);

        $activity = $this->factory()->from($event);

        $this->assertSame('job_failed', $activity->type);
        $this->assertNull($activity->payload['exception_class']);
        $this->assertNull($activity->payload['exception_message']);
    }

    public function test_translates_job_completed(): void
    {
        $pushedAt = (string) (microtime(true) - 0.5);
        $payload = new JobPayload(json_encode([
            'uuid' => 'c-1',
            'displayName' => 'App\\Jobs\\Foo',
            'pushedAt' => $pushedAt,
        ]));
        $event = new JobCompleted('sqs', 'default', $payload);

        $activity = $this->factory()->from($event);

        $this->assertSame('job_completed', $activity->type);
        $this->assertSame('c-1', $activity->payload['job_id']);
        $this->assertSame('App\\Jobs\\Foo', $activity->payload['job_class']);
        $this->assertSame('sqs', $activity->payload['connection']);
        $this->assertSame('default', $activity->payload['queue']);
        $this->assertArrayHasKey('duration_ms', $activity->payload);
        $this->assertIsInt($activity->payload['duration_ms']);
        $this->assertGreaterThan(0, $activity->payload['duration_ms']);
    }

    public function test_job_completed_handles_missing_pushed_at(): void
    {
        $payload = new JobPayload(json_encode([
            'uuid' => 'c-2',
            'displayName' => 'App\\Jobs\\Bar',
        ]));
        $event = new JobCompleted('redis', 'default', $payload);

        $activity = $this->factory()->from($event);

        $this->assertSame('job_completed', $activity->type);
        $this->assertNull($activity->payload['duration_ms']);
    }

    public function test_translates_job_rate_limited(): void
    {
        $payload = new JobPayload(json_encode([
            'uuid' => 'r-1',
            'displayName' => 'App\\Jobs\\Heavy',
        ]));
        $event = new JobRateLimited(
            connection: 'sqs',
            queueName: 'rate-limited',
            limitName: 'concurrency:heavy',
            retryAfterSeconds: 30,
            strategy: 'concurrency',
            payload: $payload,
        );

        $activity = $this->factory()->from($event);

        $this->assertSame('job_rate_limited', $activity->type);
        $this->assertSame('r-1', $activity->payload['job_id']);
        $this->assertSame('App\\Jobs\\Heavy', $activity->payload['job_class']);
        $this->assertSame('sqs', $activity->payload['connection']);
        $this->assertSame('rate-limited', $activity->payload['queue']);
        $this->assertSame('concurrency:heavy', $activity->payload['limit_name']);
        $this->assertSame(30, $activity->payload['retry_after']);
        $this->assertSame('concurrency', $activity->payload['strategy']);
    }

    public function test_translates_job_queued(): void
    {
        $payload = new JobPayload(json_encode([
            'uuid' => 'q-1',
            'displayName' => 'App\\Jobs\\Send',
        ]));
        $event = new JobQueued('sqs', 'default', $payload);

        $activity = $this->factory()->from($event);

        $this->assertSame('job_queued', $activity->type);
        $this->assertSame('q-1', $activity->payload['job_id']);
        $this->assertSame('App\\Jobs\\Send', $activity->payload['job_class']);
        $this->assertSame('sqs', $activity->payload['connection']);
        $this->assertSame('default', $activity->payload['queue']);
    }

    public function test_translates_worker_process_restarting(): void
    {
        $sym = Mockery::mock(Process::class);
        $sym->shouldReceive('getPid')->andReturn(4242)->byDefault();
        $sym->shouldReceive('getCommandLine')->andReturn('php artisan sunset:worker sqs --queue=default')->byDefault();

        $worker = new WorkerProcess($sym);

        $activity = $this->factory()->from(new WorkerProcessRestarting($worker));

        $this->assertSame('worker_process_restarting', $activity->type);
        $this->assertSame(4242, $activity->payload['pid']);
        $this->assertArrayHasKey('command', $activity->payload);
        $this->assertStringContainsString('sunset:worker', $activity->payload['command']);
    }

    public function test_worker_process_restarting_handles_null_pid(): void
    {
        $sym = Mockery::mock(Process::class);
        $sym->shouldReceive('getPid')->andReturn(null)->byDefault();
        $sym->shouldReceive('getCommandLine')->andReturn('php artisan sunset:worker sqs')->byDefault();

        $worker = new WorkerProcess($sym);

        $activity = $this->factory()->from(new WorkerProcessRestarting($worker));

        $this->assertSame('worker_process_restarting', $activity->type);
        $this->assertNull($activity->payload['pid']);
    }

    public function test_translates_unable_to_launch_process(): void
    {
        $sym = Mockery::mock(Process::class);
        $sym->shouldReceive('getPid')->andReturn(null)->byDefault();
        $sym->shouldReceive('getCommandLine')->andReturn('php artisan sunset:worker rabbit --queue=jobs')->byDefault();

        $worker = new WorkerProcess($sym);

        $activity = $this->factory()->from(new UnableToLaunchProcess($worker));

        $this->assertSame('unable_to_launch_process', $activity->type);
        $this->assertNull($activity->payload['pid']);
        $this->assertSame('php artisan sunset:worker rabbit --queue=jobs', $activity->payload['command']);
    }

    public function test_translates_long_wait_detected(): void
    {
        $activity = $this->factory(1_700_001_234)->from(new LongWaitDetected('sqs', 'default', 60));

        $this->assertSame('long_wait_detected', $activity->type);
        $this->assertSame(1_700_001_234, $activity->occurredAt);
        $this->assertSame('sqs', $activity->payload['connection']);
        $this->assertSame('default', $activity->payload['queue']);
        $this->assertSame(60, $activity->payload['seconds']);
    }

    public function test_translates_master_supervisor_deployed(): void
    {
        $activity = $this->factory()->from(new MasterSupervisorDeployed('master-abc'));

        $this->assertSame('master_supervisor_deployed', $activity->type);
        $this->assertSame('master-abc', $activity->payload['master_name']);
    }

    public function test_translates_queue_paused(): void
    {
        $activity = $this->factory(1_700_002_222)->from(new QueuePaused('redis', 'default', 'cli'));

        $this->assertInstanceOf(ActivityEvent::class, $activity);
        $this->assertSame(0, $activity->id);
        $this->assertSame('queue_paused', $activity->type);
        $this->assertSame(1_700_002_222, $activity->occurredAt);
        $this->assertSame(
            ['connection' => 'redis', 'queue' => 'default', 'actor' => 'cli'],
            $activity->payload,
        );
    }

    public function test_translates_queue_paused_with_null_actor(): void
    {
        $activity = $this->factory()->from(new QueuePaused('sqs', 'orders'));

        $this->assertSame('queue_paused', $activity->type);
        $this->assertSame(
            ['connection' => 'sqs', 'queue' => 'orders', 'actor' => null],
            $activity->payload,
        );
    }

    public function test_translates_queue_resumed(): void
    {
        $activity = $this->factory(1_700_003_333)->from(new QueueResumed('redis', 'default', 'dashboard'));

        $this->assertInstanceOf(ActivityEvent::class, $activity);
        $this->assertSame(0, $activity->id);
        $this->assertSame('queue_resumed', $activity->type);
        $this->assertSame(1_700_003_333, $activity->occurredAt);
        $this->assertSame(
            ['connection' => 'redis', 'queue' => 'default', 'actor' => 'dashboard'],
            $activity->payload,
        );
    }

    public function test_uses_injected_clock_for_occurred_at(): void
    {
        $factory = new ActivityEventFactory(fn () => 1_555_555_555);
        $activity = $factory->from(new MasterSupervisorDeployed('x'));

        $this->assertSame(1_555_555_555, $activity->occurredAt);
    }

    protected function tearDown(): void { Mockery::close(); parent::tearDown(); }
}
