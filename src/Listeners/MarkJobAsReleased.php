<?php

namespace Admnio\Sunset\Listeners;

use Admnio\Sunset\Contracts\JobRepository;
use Admnio\Sunset\Events\JobReleased;

/**
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on the published Admnio\Sunset\Contracts\* interfaces instead.
 */
class MarkJobAsReleased
{
    public function __construct(private JobRepository $jobs) {}

    public function handle(JobReleased $event): void
    {
        $delay = (int) ($event->payload->decoded['delay'] ?? 0);
        $this->jobs->released($event->connectionName, $event->queue, $event->payload, $delay);
    }
}
