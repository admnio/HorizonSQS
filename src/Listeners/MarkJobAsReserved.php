<?php

namespace Admnio\Sunset\Listeners;

use Admnio\Sunset\Contracts\JobRepository;
use Admnio\Sunset\Events\JobReserved;

class MarkJobAsReserved
{
    public function __construct(private JobRepository $jobs) {}

    public function handle(JobReserved $event): void
    {
        $this->jobs->reserved($event->connectionName, $event->queue, $event->payload);
    }
}
