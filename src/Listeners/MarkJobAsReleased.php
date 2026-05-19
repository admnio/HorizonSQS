<?php

namespace Admnio\Sunset\Listeners;

use Admnio\Sunset\Contracts\JobRepository;
use Admnio\Sunset\Events\JobReleased;

class MarkJobAsReleased
{
    public function __construct(private JobRepository $jobs) {}

    public function handle(JobReleased $event): void
    {
        $delay = (int) ($event->payload->decoded['delay'] ?? 0);
        $this->jobs->released($event->connectionName, $event->queue, $event->payload, $delay);
    }
}
