<?php

namespace Admnio\Sunset\Listeners;

use Admnio\Sunset\Contracts\JobRepository;
use Admnio\Sunset\Contracts\MetricsRepository;
use Admnio\Sunset\Events\JobCompleted;

/**
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on the published Admnio\Sunset\Contracts\* interfaces instead.
 */
class MarkJobAsComplete
{
    public function __construct(
        private JobRepository $jobs,
        private MetricsRepository $metrics,
    ) {}

    public function handle(JobCompleted $event): void
    {
        $silenced = (bool) ($event->payload->decoded['silenced'] ?? false);
        $this->jobs->completed($event->payload, $silenced);

        $name = $event->payload->decoded['displayName'] ?? '';
        $pushedAt = (float) ($event->payload->decoded['pushedAt'] ?? microtime(true));
        $runtime = max(0.0, microtime(true) - $pushedAt);

        $this->metrics->incrementThroughput($name, $event->queue, $runtime);
    }
}
