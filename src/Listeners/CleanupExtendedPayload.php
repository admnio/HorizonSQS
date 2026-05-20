<?php

namespace Admnio\Sunset\Listeners;

use Illuminate\Queue\Events\JobProcessed;
use Admnio\Sunset\Transports\Sqs\Payload\ExtendedPayloadHandler;

/**
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on the published Admnio\Sunset\Contracts\* interfaces instead.
 */
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

        // Prefer the stashed original body (set in SqsQueue::pop) since
        // getRawBody() returns the post-fetch expanded payload, which never
        // contains the s3PointerKey field.
        $body = $event->job->getRawBody();
        if (method_exists($job, 'getSqsJob')) {
            $raw = $job->getSqsJob();
            if (is_array($raw) && isset($raw['SunsetSqsOriginalBody'])) {
                $body = $raw['SunsetSqsOriginalBody'];
            }
        }

        $this->handler->deleteIfPointer($body);
    }
}
