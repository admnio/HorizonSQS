<?php

namespace Admnio\Sunset\Listeners;

use Admnio\Sunset\Contracts\JobRepository;
use Admnio\Sunset\Contracts\TagRepository;
use Admnio\Sunset\Events\JobQueueing;
use Carbon\CarbonImmutable;

class StorePendingJob
{
    public function __construct(
        private JobRepository $jobs,
        private TagRepository $tags,
    ) {}

    public function handle(JobQueueing $event): void
    {
        $this->jobs->pushed($event->connectionName, $event->queue, $event->payload);

        $payloadTags = $event->payload->tags();
        if (! empty($payloadTags)) {
            $expiresAt = CarbonImmutable::now()->addMinutes(
                (int) config('sunset.trim.pending', 60)
            )->getTimestamp();
            $this->tags->addTemporary($expiresAt, $event->payload->id(), $payloadTags);
        }
    }
}
