<?php

namespace Admnio\Sunset\Listeners;

use Admnio\Sunset\Contracts\TagRepository;
use Admnio\Sunset\Events\JobQueueing;

class MonitorTag
{
    public function __construct(private TagRepository $tags) {}

    public function handle(JobQueueing $event): void
    {
        $payloadTags = $event->payload->tags();
        if (empty($payloadTags)) {
            return;
        }

        $monitored = $this->tags->monitored();
        if (empty(array_intersect($payloadTags, $monitored))) {
            return;
        }

        // Promote the index entry from temporary (StorePendingJob's write) to
        // permanent so monitored jobs survive longer.
        $this->tags->addPermanent($event->payload->id(), $payloadTags);
    }
}
