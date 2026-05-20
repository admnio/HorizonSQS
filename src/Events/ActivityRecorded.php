<?php

namespace Admnio\Sunset\Events;

use Admnio\Sunset\Activity\ActivityEvent;

/**
 * Fired by the ActivityRecorder after each successful write to the
 * activity-stream replay buffer. Carries the persisted ActivityEvent —
 * including its monotonic id — so consumers can forward activity to their
 * own observability stack.
 *
 * Subscribe to this event when you want to fan worker-tier activity out
 * to a side channel: post job failures to Slack, mirror the stream into
 * an audit log, push into Datadog, etc. The dashboard's own SSE feed is
 * served straight off the Redis buffer; subscribing here is for everything
 * that lives outside the dashboard.
 *
 * Stability: part of the v1.x public API. The DTO shape on $event is
 * locked alongside Admnio\Sunset\Activity\ActivityEvent and
 * Admnio\Sunset\Contracts\ActivityRepository.
 */
class ActivityRecorded
{
    public function __construct(
        public readonly ActivityEvent $event,
    ) {
    }
}
