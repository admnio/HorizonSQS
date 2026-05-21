<?php

namespace Admnio\Sunset\Activity;

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
use Closure;

/**
 * Translates Sunset domain events into ActivityEvent value objects for the
 * activity stream's replay buffer.
 *
 * Returns null for any event the activity stream does not capture. The id on
 * the returned ActivityEvent is always 0; the recorder replaces it with the
 * real INCR-assigned id before persisting.
 *
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on the published Admnio\Sunset\Contracts\ActivityRepository +
 *           Admnio\Sunset\Activity\ActivityEvent surface instead.
 */
final class ActivityEventFactory
{
    /**
     * @param Closure $clock fn(): int — unix seconds. Injected for tests.
     */
    public function __construct(private readonly Closure $clock)
    {
    }

    /**
     * Translate a Sunset event into an ActivityEvent, or return null if the
     * event isn't part of the captured set.
     */
    public function from(object $event): ?ActivityEvent
    {
        return match (true) {
            $event instanceof JobFailed                => $this->fromJobFailed($event),
            $event instanceof JobCompleted             => $this->fromJobCompleted($event),
            $event instanceof JobRateLimited           => $this->fromJobRateLimited($event),
            $event instanceof JobQueued                => $this->fromJobQueued($event),
            $event instanceof WorkerProcessRestarting  => $this->fromWorkerProcessEvent($event, 'worker_process_restarting'),
            $event instanceof UnableToLaunchProcess    => $this->fromWorkerProcessEvent($event, 'unable_to_launch_process'),
            $event instanceof LongWaitDetected         => $this->fromLongWaitDetected($event),
            $event instanceof MasterSupervisorDeployed => $this->fromMasterSupervisorDeployed($event),
            $event instanceof QueuePaused              => $this->fromQueuePauseEvent($event, 'queue_paused'),
            $event instanceof QueueResumed             => $this->fromQueuePauseEvent($event, 'queue_resumed'),
            default                                    => null,
        };
    }

    private function now(): int
    {
        return (int) ($this->clock)();
    }

    private function fromJobFailed(JobFailed $event): ActivityEvent
    {
        $decodedException = $this->decodeExceptionData($event->payload->decoded['exception_data'] ?? null);

        return new ActivityEvent(
            id: 0,
            type: 'job_failed',
            occurredAt: $this->now(),
            payload: [
                'job_id' => $event->payload->id(),
                'job_class' => $event->payload->displayName(),
                'connection' => $event->connectionName,
                'queue' => $event->queue,
                'exception_class' => $decodedException['class'] ?? null,
                'exception_message' => $decodedException['message'] ?? null,
            ],
        );
    }

    private function fromJobCompleted(JobCompleted $event): ActivityEvent
    {
        return new ActivityEvent(
            id: 0,
            type: 'job_completed',
            occurredAt: $this->now(),
            payload: [
                'job_id' => $event->payload->id(),
                'job_class' => $event->payload->displayName(),
                'connection' => $event->connectionName,
                'queue' => $event->queue,
                'duration_ms' => $this->durationMs($event->payload->decoded['pushedAt'] ?? null),
            ],
        );
    }

    private function fromJobRateLimited(JobRateLimited $event): ActivityEvent
    {
        return new ActivityEvent(
            id: 0,
            type: 'job_rate_limited',
            occurredAt: $this->now(),
            payload: [
                'job_id' => $event->payload->id(),
                'job_class' => $event->payload->displayName(),
                'connection' => $event->connection,
                'queue' => $event->queueName,
                'limit_name' => $event->limitName,
                'retry_after' => $event->retryAfterSeconds,
                'strategy' => $event->strategy,
            ],
        );
    }

    private function fromJobQueued(JobQueued $event): ActivityEvent
    {
        return new ActivityEvent(
            id: 0,
            type: 'job_queued',
            occurredAt: $this->now(),
            payload: [
                'job_id' => $event->payload->id(),
                'job_class' => $event->payload->displayName(),
                'connection' => $event->connectionName,
                'queue' => $event->queue,
            ],
        );
    }

    /**
     * The supervisor's WorkerProcess wraps a Symfony Process and uses
     * __call() to pass through. We read the pid + command line from the
     * inner process; both may be unavailable (e.g. process never started).
     *
     * @param WorkerProcessRestarting|UnableToLaunchProcess $event
     */
    private function fromWorkerProcessEvent(object $event, string $type): ActivityEvent
    {
        $worker = $event->process;
        $symfonyProcess = $worker->process ?? null;

        $pid = null;
        $command = null;
        if ($symfonyProcess !== null) {
            try {
                $pid = $symfonyProcess->getPid();
            } catch (\Throwable) {
                $pid = null;
            }
            try {
                $command = $symfonyProcess->getCommandLine();
            } catch (\Throwable) {
                $command = null;
            }
        }

        return new ActivityEvent(
            id: 0,
            type: $type,
            occurredAt: $this->now(),
            payload: [
                'pid' => $pid,
                'command' => $command,
            ],
        );
    }

    private function fromLongWaitDetected(LongWaitDetected $event): ActivityEvent
    {
        return new ActivityEvent(
            id: 0,
            type: 'long_wait_detected',
            occurredAt: $this->now(),
            payload: [
                'connection' => $event->connection,
                'queue' => $event->queue,
                'seconds' => $event->seconds,
            ],
        );
    }

    private function fromMasterSupervisorDeployed(MasterSupervisorDeployed $event): ActivityEvent
    {
        return new ActivityEvent(
            id: 0,
            type: 'master_supervisor_deployed',
            occurredAt: $this->now(),
            payload: [
                'master_name' => $event->master,
            ],
        );
    }

    /**
     * v1.3.0 — pause/resume share the same payload shape (connection, queue,
     * actor); only the activity type string differs. Collapsed into one helper
     * so the divergence stays at the single line in the match() chain above
     * rather than being scattered across two near-identical methods.
     *
     * @param QueuePaused|QueueResumed $event
     */
    private function fromQueuePauseEvent(object $event, string $type): ActivityEvent
    {
        return new ActivityEvent(
            id: 0,
            type: $type,
            occurredAt: $this->now(),
            payload: [
                'connection' => $event->connection,
                'queue'      => $event->queue,
                'actor'      => $event->actor,
            ],
        );
    }

    /**
     * @return array{class?: string, message?: string}|array{}
     */
    private function decodeExceptionData(?string $raw): array
    {
        if (! is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Convert a pushedAt microtime float-string to runtime in milliseconds,
     * relative to the injected clock. Returns null if pushedAt is missing or
     * unparseable.
     */
    private function durationMs(mixed $pushedAt): ?int
    {
        if ($pushedAt === null || $pushedAt === '') {
            return null;
        }
        $pushedFloat = (float) $pushedAt;
        if ($pushedFloat <= 0) {
            return null;
        }
        $elapsed = microtime(true) - $pushedFloat;
        if ($elapsed < 0) {
            return null;
        }

        return (int) round($elapsed * 1000);
    }
}
