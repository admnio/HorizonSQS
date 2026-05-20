<?php

namespace Admnio\Sunset\Listeners;

use Admnio\Sunset\Events\JobCompleted;
use Admnio\Sunset\JobPayload;
use Illuminate\Queue\Events\JobProcessed;

/**
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on the published Admnio\Sunset\Contracts\* interfaces instead.
 */
class TranslateJobProcessed
{
    public function handle(JobProcessed $event): void
    {
        $payload = new JobPayload($event->job->getRawBody());

        event(new JobCompleted(
            $event->connectionName,
            $event->job->getQueue() ?? '',
            $payload
        ));
    }
}
