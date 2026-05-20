<?php

namespace Admnio\Sunset\RateLimiting\Listeners;

use Admnio\Sunset\Contracts\Limiter;
use Admnio\Sunset\RateLimiting\RateLimitGate;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;

/**
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on the published Admnio\Sunset\Contracts\* interfaces instead.
 */
class ReleaseConcurrencySlots
{
    public function __construct(
        private Limiter $limiter,
        private RateLimitGate $gate,
    ) {
    }

    public function handle(JobProcessed|JobFailed|JobExceptionOccurred $event): void
    {
        $jobId = $event->job->getJobId();
        if (! $jobId) {
            return;
        }

        $reservations = $this->gate->readReservations($jobId);
        if ($reservations === []) {
            return;
        }

        $this->limiter->release($reservations);
        $this->gate->clearReservations($jobId);
    }
}
