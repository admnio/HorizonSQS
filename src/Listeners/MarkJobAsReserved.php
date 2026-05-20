<?php

namespace Admnio\Sunset\Listeners;

use Admnio\Sunset\Contracts\JobRepository;
use Admnio\Sunset\Events\JobReserved;

/**
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on the published Admnio\Sunset\Contracts\* interfaces instead.
 */
class MarkJobAsReserved
{
    public function __construct(private JobRepository $jobs) {}

    public function handle(JobReserved $event): void
    {
        $this->jobs->reserved($event->connectionName, $event->queue, $event->payload);
    }
}
