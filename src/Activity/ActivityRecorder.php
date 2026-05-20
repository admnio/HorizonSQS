<?php

namespace Admnio\Sunset\Activity;

use Admnio\Sunset\Events\ActivityRecorded;
use Admnio\Sunset\Repositories\Redis\RedisActivityRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers wanting to
 *           react to activity events should subscribe to
 *           Admnio\Sunset\Events\ActivityRecorded — that surface is stable.
 *
 * The recorder is a single Laravel subscriber-style listener bound to all 8
 * captured Sunset events in the service provider. handle() runs once per
 * source event:
 *   1. If activity is disabled in config, short-circuit silently.
 *   2. Ask the factory to translate the source event into an ActivityEvent.
 *      The factory returns null for events outside the captured set.
 *   3. Persist via the repository — this assigns the monotonic id.
 *   4. Dispatch ActivityRecorded so external subscribers (Slack forwarders,
 *      audit-log mirrors, etc.) can pick it up.
 *
 * Failure semantics: any Throwable from steps 3 or 4 is caught, debug-logged,
 * and swallowed. Telemetry is observability, not load-bearing — a Redis
 * outage or a buggy downstream listener must not propagate into the worker
 * loop and crash job processing.
 *
 * The repository is type-hinted as the concrete RedisActivityRepository (not
 * the ActivityRepository contract) because the write method `record()` is
 * deliberately not part of the public contract; only the recorder writes.
 */
class ActivityRecorder
{
    public function __construct(
        private readonly ActivityEventFactory $factory,
        private readonly RedisActivityRepository $repository,
        private readonly Dispatcher $events,
        private readonly LoggerInterface $logger,
        private readonly bool $enabled,
    ) {
    }

    public function handle(object $event): void
    {
        if (! $this->enabled) {
            return;
        }

        $activityEvent = $this->factory->from($event);
        if ($activityEvent === null) {
            return;
        }

        try {
            $assigned = $this->repository->record($activityEvent);

            // The ActivityRecorded dispatch is inside the same try block on
            // purpose: a buggy consumer listener that throws here must not
            // crash the worker loop. The tradeoff is that we silently swallow
            // a real exception thrown by a consumer's own listener — operators
            // see it as a debug-level log line rather than a fatal. Acceptable
            // for v1.x; consumer listeners should do their own error handling
            // and not lean on the recorder to surface their bugs.
            $this->events->dispatch(new ActivityRecorded($assigned));
        } catch (Throwable $e) {
            $this->logger->debug(
                'Sunset: ActivityRecorder failed to record or dispatch activity event.',
                [
                    'exception' => $e,
                    'event_type' => get_class($event),
                ],
            );
        }
    }
}
