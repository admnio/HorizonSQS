<?php

namespace Admnio\Sunset\Listeners;

use Admnio\Sunset\Contracts\JobRepository;
use Admnio\Sunset\Events\JobQueued;

class StoreJob
{
    public function __construct(private JobRepository $jobs) {}

    public function handle(JobQueued $event): void
    {
        // JobQueued fires AFTER the queue ack. StorePendingJob already wrote
        // the pending record on JobQueueing; here we reaffirm/refresh the
        // entry so any race against in-flight reads converges.
        $this->jobs->pushed($event->connectionName, $event->queue, $event->payload);
    }
}
