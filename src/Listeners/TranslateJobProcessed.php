<?php

namespace Admnio\Sunset\Listeners;

use Admnio\Sunset\Events\JobCompleted;
use Admnio\Sunset\JobPayload;
use Illuminate\Queue\Events\JobProcessed;

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
