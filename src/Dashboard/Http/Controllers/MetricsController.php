<?php

namespace Admnio\Sunset\Dashboard\Http\Controllers;

use Admnio\Sunset\Contracts\MetricsRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Response as InertiaResponse;

/**
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on the published Admnio\Sunset\Contracts\* interfaces instead.
 */
final class MetricsController extends Controller
{
    public function show(Request $request, MetricsRepository $metrics): InertiaResponse|JsonResponse
    {
        $jobs   = $metrics->jobs();
        $queues = $metrics->queues();

        return $this->inertiaOrJson($request, 'Sunset/Metrics', [
            'jobs'             => $jobs,
            'queues'           => $queues,
            'snapshot_taken_at'=> $metrics->latestSnapshotAt(),
            'wait_times'       => $metrics->acquireWaitTimes(),
        ]);
    }

    public function jobSeries(string $job, MetricsRepository $metrics): JsonResponse
    {
        $snapshots = $metrics->snapshotsForJob($job);

        return response()->json([
            'job'        => $job,
            'snapshots'  => $snapshots,
            'points'     => $this->normalize($snapshots),
            'throughput' => $metrics->throughputForJob($job),
            'runtime'    => $metrics->runtimeForJob($job),
        ]);
    }

    public function queueSeries(string $queue, MetricsRepository $metrics): JsonResponse
    {
        $snapshots = $metrics->snapshotsForQueue($queue);

        return response()->json([
            'queue'      => $queue,
            'snapshots'  => $snapshots,
            'points'     => $this->normalize($snapshots),
            'throughput' => $metrics->throughputForQueue($queue),
            'runtime'    => $metrics->runtimeForQueue($queue),
        ]);
    }

    /**
     * Batched series endpoint. Accepts `jobs[]` and `queues[]` query lists and
     * returns a single response keyed by name with normalized point arrays.
     * Used by the Metrics dashboard to avoid N parallel per-name fetches when
     * the application has many distinct job classes.
     */
    public function series(Request $request, MetricsRepository $metrics): JsonResponse
    {
        $jobs   = (array) $request->query('jobs', []);
        $queues = (array) $request->query('queues', []);

        // Cap input size to prevent unbounded fan-out.
        $jobs   = array_slice($jobs, 0, 100);
        $queues = array_slice($queues, 0, 100);

        $jobSeries = [];
        foreach ($jobs as $name) {
            $jobSeries[$name] = $this->normalize($metrics->snapshotsForJob((string) $name));
        }

        $queueSeries = [];
        foreach ($queues as $name) {
            $queueSeries[$name] = $this->normalize($metrics->snapshotsForQueue((string) $name));
        }

        return response()->json([
            'jobs'   => $jobSeries,
            'queues' => $queueSeries,
        ]);
    }

    /**
     * Reduce a list of snapshot rows (each `['time' => ..., 'throughput' => ...,
     * 'runtime' => ...]`) to a normalized 0..1 array of throughput values
     * suitable for the dashboard's <Sparkline> component.
     */
    private function normalize(array $snapshots): array
    {
        $values = array_map(
            static fn ($s) => (float) ($s['throughput'] ?? 0),
            $snapshots
        );

        if ($values === []) {
            return [];
        }

        $max = max($values);
        if ($max <= 0) {
            return array_fill(0, count($values), 0.0);
        }

        return array_map(static fn ($v) => $v / $max, $values);
    }
}
