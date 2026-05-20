<?php

namespace Admnio\Sunset\Tests\Unit\Activity;

use Admnio\Sunset\Activity\ActivityEvent;
use Admnio\Sunset\Activity\ActivityEventFactory;
use Admnio\Sunset\Activity\ActivityRecorder;
use Admnio\Sunset\Events\ActivityRecorded;
use Admnio\Sunset\Events\MasterSupervisorDeployed;
use Admnio\Sunset\Repositories\Redis\RedisActivityRepository;
use Admnio\Sunset\Tests\TestCase;
use Illuminate\Contracts\Events\Dispatcher;
use Mockery;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use stdClass;

class ActivityRecorderTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * ActivityEventFactory is final so we can't Mockery::mock() it; use a real
     * one with a fixed clock. MasterSupervisorDeployed is the cheapest captured
     * event (single string field) — it round-trips through the factory cleanly
     * and gives us a deterministic ActivityEvent to assert on.
     */
    private function realFactory(int $now = 1_700_000_000): ActivityEventFactory
    {
        return new ActivityEventFactory(fn () => $now);
    }

    public function test_does_nothing_when_disabled(): void
    {
        $repo = Mockery::mock(RedisActivityRepository::class);
        $repo->shouldNotReceive('record');

        $events = Mockery::mock(Dispatcher::class);
        $events->shouldNotReceive('dispatch');

        $recorder = new ActivityRecorder(
            factory: $this->realFactory(),
            repository: $repo,
            events: $events,
            logger: new NullLogger(),
            enabled: false,
        );

        // Even a captured event must short-circuit when disabled.
        $recorder->handle(new MasterSupervisorDeployed('master-1'));

        $this->assertTrue(true);
    }

    public function test_does_nothing_when_factory_returns_null(): void
    {
        $repo = Mockery::mock(RedisActivityRepository::class);
        $repo->shouldNotReceive('record');

        $events = Mockery::mock(Dispatcher::class);
        $events->shouldNotReceive('dispatch');

        $recorder = new ActivityRecorder(
            factory: $this->realFactory(),
            repository: $repo,
            events: $events,
            logger: new NullLogger(),
            enabled: true,
        );

        // stdClass is not in the captured set, so factory returns null.
        $recorder->handle(new stdClass());

        $this->assertTrue(true);
    }

    public function test_records_event_and_dispatches_activity_recorded_with_assigned_id(): void
    {
        $sourceEvent = new MasterSupervisorDeployed('master-xyz');

        // The factory will produce an ActivityEvent with id=0; the repository
        // assigns the real id and returns the updated event.
        $assigned = new ActivityEvent(
            id: 99,
            type: 'master_supervisor_deployed',
            occurredAt: 1_700_000_000,
            payload: ['master_name' => 'master-xyz'],
        );

        $repo = Mockery::mock(RedisActivityRepository::class);
        $repo->shouldReceive('record')
            ->once()
            ->with(Mockery::on(function (ActivityEvent $e) {
                return $e->id === 0
                    && $e->type === 'master_supervisor_deployed'
                    && $e->payload['master_name'] === 'master-xyz';
            }))
            ->andReturn($assigned);

        $dispatched = null;
        $events = Mockery::mock(Dispatcher::class);
        $events->shouldReceive('dispatch')
            ->once()
            ->with(Mockery::on(function ($event) use (&$dispatched) {
                $dispatched = $event;

                return $event instanceof ActivityRecorded;
            }));

        $recorder = new ActivityRecorder(
            factory: $this->realFactory(),
            repository: $repo,
            events: $events,
            logger: new NullLogger(),
            enabled: true,
        );

        $recorder->handle($sourceEvent);

        $this->assertInstanceOf(ActivityRecorded::class, $dispatched);
        $this->assertSame(99, $dispatched->event->id);
        $this->assertSame('master_supervisor_deployed', $dispatched->event->type);
        $this->assertSame(['master_name' => 'master-xyz'], $dispatched->event->payload);
    }

    public function test_swallows_repository_exception_and_logs_at_debug(): void
    {
        $sourceEvent = new MasterSupervisorDeployed('master-2');

        $repo = Mockery::mock(RedisActivityRepository::class);
        $repo->shouldReceive('record')
            ->once()
            ->andThrow(new RuntimeException('Redis gone'));

        $events = Mockery::mock(Dispatcher::class);
        $events->shouldNotReceive('dispatch');

        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('debug')
            ->once()
            ->withArgs(function ($message, $context) use ($sourceEvent) {
                return is_string($message)
                    && str_contains($message, 'Sunset')
                    && isset($context['exception'])
                    && $context['exception'] instanceof RuntimeException
                    && isset($context['event_type'])
                    && $context['event_type'] === get_class($sourceEvent);
            });

        $recorder = new ActivityRecorder(
            factory: $this->realFactory(),
            repository: $repo,
            events: $events,
            logger: $logger,
            enabled: true,
        );

        // Must not throw.
        $recorder->handle($sourceEvent);

        $this->assertTrue(true);
    }

    public function test_swallows_dispatcher_exception_thrown_by_consumer_listener(): void
    {
        // A buggy consumer listener can throw out of Event::dispatch(...). The
        // recorder must not crash the worker — telemetry is observability, not
        // load-bearing. The error is debug-logged so operators can find it.
        $sourceEvent = new MasterSupervisorDeployed('master-3');

        $assigned = new ActivityEvent(
            id: 7,
            type: 'master_supervisor_deployed',
            occurredAt: 1_700_000_000,
            payload: ['master_name' => 'master-3'],
        );

        $repo = Mockery::mock(RedisActivityRepository::class);
        $repo->shouldReceive('record')->once()->andReturn($assigned);

        $events = Mockery::mock(Dispatcher::class);
        $events->shouldReceive('dispatch')
            ->once()
            ->andThrow(new RuntimeException('consumer listener bug'));

        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('debug')
            ->once()
            ->withArgs(function ($message, $context) {
                return is_string($message)
                    && isset($context['exception'])
                    && $context['exception'] instanceof RuntimeException
                    && isset($context['event_type']);
            });

        $recorder = new ActivityRecorder(
            factory: $this->realFactory(),
            repository: $repo,
            events: $events,
            logger: $logger,
            enabled: true,
        );

        // Must not throw — a downstream listener bug cannot crash the worker.
        $recorder->handle($sourceEvent);

        $this->assertTrue(true);
    }
}
