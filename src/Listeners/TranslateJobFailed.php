<?php

namespace Admnio\Sunset\Listeners;

use Admnio\Sunset\Events\JobFailed as SunsetJobFailed;
use Admnio\Sunset\JobPayload;
use Illuminate\Queue\Events\JobFailed as LaravelJobFailed;

/**
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on the published Admnio\Sunset\Contracts\* interfaces instead.
 */
class TranslateJobFailed
{
    public function handle(LaravelJobFailed $event): void
    {
        $payload = new JobPayload($event->job->getRawBody());

        $payload->set([
            'exception_data' => json_encode([
                'class' => get_class($event->exception),
                'message' => $event->exception->getMessage(),
                'file' => $event->exception->getFile(),
                'line' => $event->exception->getLine(),
            ]),
        ]);

        event(new SunsetJobFailed(
            $event->connectionName,
            $event->job->getQueue() ?? '',
            $payload
        ));
    }
}
