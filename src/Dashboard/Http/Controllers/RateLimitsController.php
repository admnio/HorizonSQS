<?php

namespace Admnio\Sunset\Dashboard\Http\Controllers;

use Admnio\Sunset\RateLimiting\Limit;
use Admnio\Sunset\RateLimiting\LimitRegistry;
use Admnio\Sunset\RateLimiting\RateLimitStatsRepository;
use Admnio\Sunset\RateLimiting\Targets\JobClassTarget;
use Admnio\Sunset\RateLimiting\Targets\QueueTarget;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Response as InertiaResponse;

/**
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on the published Admnio\Sunset\Contracts\* interfaces instead.
 */
final class RateLimitsController extends Controller
{
    public function show(
        Request $request,
        LimitRegistry $registry,
        RateLimitStatsRepository $stats,
    ): InertiaResponse|JsonResponse {
        $limits = array_map(
            fn (Limit $limit) => $this->serializeLimit($limit),
            $registry->all()
        );

        return $this->inertiaOrJson($request, 'Sunset/RateLimits', [
            'limits'  => $limits,
            'rejects' => $stats->rejectsByLimit(),
        ]);
    }

    private function serializeLimit(Limit $limit): array
    {
        return [
            'name'        => $limit->name,
            'target'      => $this->targetDescription($limit->target),
            'throttle'    => $limit->throttle ? [
                'max'    => $limit->throttle->max,
                'window' => $limit->throttle->windowSeconds,
            ] : null,
            'concurrency' => $limit->concurrency ? [
                'max'      => $limit->concurrency->max,
                'slot_ttl' => $limit->concurrency->slotTtlSeconds,
            ] : null,
            'over_limit'             => $limit->overLimit,
            'fixed_backoff_seconds'  => $limit->fixedBackoffSeconds,
            'drop_as_failure'        => $limit->dropAsFailure,
            'count_releases'         => $limit->countReleases,
        ];
    }

    private function targetDescription(QueueTarget|JobClassTarget $target): string
    {
        if ($target instanceof QueueTarget) {
            return 'queue:' . $target->queueName;
        }

        return 'class:' . $target->jobClass;
    }
}
