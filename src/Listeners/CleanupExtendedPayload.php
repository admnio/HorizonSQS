<?php

namespace Admnio\Sunset\Listeners;

use Illuminate\Queue\Events\JobProcessed;
use Admnio\Sunset\Queue\Payload\ExtendedPayloadHandler;

class CleanupExtendedPayload
{
    public function __construct(private ExtendedPayloadHandler $handler)
    {
    }

    public function handle(JobProcessed $event): void
    {
        if ($event->connectionName !== 'sqs') {
            return;
        }

        $job = $event->job;

        // Prefer the stashed original body (set in HorizonSqsQueue::pop) since
        // getRawBody() returns the post-fetch expanded payload, which never
        // contains the s3PointerKey field.
        $body = $event->job->getRawBody();
        if (method_exists($job, 'getSqsJob')) {
            $raw = $job->getSqsJob();
            if (is_array($raw) && isset($raw['HorizonSqsOriginalBody'])) {
                $body = $raw['HorizonSqsOriginalBody'];
            }
        }

        $this->handler->deleteIfPointer($body);
    }
}
