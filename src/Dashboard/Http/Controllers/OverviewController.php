<?php

namespace Admnio\Sunset\Dashboard\Http\Controllers;

use Admnio\Sunset\Contracts\FailedJobRepository;
use Admnio\Sunset\Contracts\JobRepository;
use Admnio\Sunset\Contracts\MasterSupervisorRepository;
use Admnio\Sunset\Contracts\MetricsRepository;
use Admnio\Sunset\Contracts\SupervisorRepository;
use Admnio\Sunset\Contracts\WorkloadRepository;
use Admnio\Sunset\Dashboard\HealthSummary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Response as InertiaResponse;

/**
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on the published Admnio\Sunset\Contracts\* interfaces instead.
 */
final class OverviewController extends Controller
{
    public function show(
        Request $request,
        WorkloadRepository $workload,
        SupervisorRepository $supervisors,
        MasterSupervisorRepository $masters,
        JobRepository $jobs,
        MetricsRepository $metrics,
        FailedJobRepository $failures,
    ): InertiaResponse|JsonResponse {
        $totalThroughput   = $this->totalThroughput($metrics);
        $failuresLastHour  = $failures->countRecentlyFailed();
        $failureRatePct    = $this->failureRatePct($totalThroughput, $failuresLastHour);

        $props = [
            'workload'            => $workload->get(),
            'supervisors'         => $supervisors->all(),
            'masters'             => $masters->all(),
            'recent'              => $jobs->getRecent()->all(),
            'throughput_per_min'  => HealthSummary::formatCount($totalThroughput),
            'failure_rate_pct'    => $failureRatePct,
            'failures_last_hour'  => $failuresLastHour,
        ];

        return $this->inertiaOrJson($request, 'Sunset/Overview', $props);
    }

    /**
     * Sum of the latest per-queue snapshot throughput values. Matches the
     * derivation used by HealthSummary so the strip's "/min" figure and the
     * Overview hero stat stay in lockstep.
     */
    private function totalThroughput(MetricsRepository $metrics): int
    {
        $total = 0;
        foreach ($metrics->queues() as $queue) {
            $snapshots = $metrics->snapshotsForQueue((string) $queue);
            if ($snapshots === []) {
                continue;
            }
            $latest = end($snapshots);
            $total += (int) ($latest['throughput'] ?? 0);
        }
        return $total;
    }

    /**
     * Failure rate as a string percentage with 2 decimals, or "—" when there
     * isn't enough throughput to compute a meaningful ratio (avoids divide-
     * by-zero and the misleading 0.00% reading on idle dashboards).
     */
    private function failureRatePct(int $throughput, int $failures): string
    {
        $total = $throughput + $failures;
        if ($total <= 0) {
            return '—';
        }
        return number_format(($failures / $total) * 100, 2, '.', '');
    }
}
