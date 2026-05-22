<?php

namespace Admnio\Sunset\Dashboard\Http\Controllers;

use Admnio\Sunset\Contracts\FailedJobRepository;
use Admnio\Sunset\Contracts\JobRepository;
use Admnio\Sunset\Contracts\MetricsRepository;
use Admnio\Sunset\Repositories\Redis\RedisMetricsRepository;
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

    /**
     * v2.0 — detail page for a single job class. Renders the Inertia
     * ClassDetail page with stats, throughput series, histogram, and
     * recent runs/failures.
     *
     * v2.2.0: percentiles + histogram are now derived from the per-class
     * 6-bucket runtime histogram recorded at job-complete time on the Redis
     * concrete repository. This replaces the v2.0 heuristic (which derived
     * percentiles from snapshot averages and bucketed snapshot-mean values
     * into the histogram — see the v2.1.0 caveat). The controller depends on
     * the concrete RedisMetricsRepository here because the bucket APIs are
     * intentionally not on the MetricsRepository contract; the contract is
     * Sunset's stable public surface and we don't break it for an internal
     * fidelity improvement. Mirrors the v1.3.0 RedisQueuePauseRepository
     * concrete-injection pattern used by WorkloadController.
     */
    public function class(
        string $name,
        Request $request,
        RedisMetricsRepository $metrics,
        JobRepository $jobs,
        FailedJobRepository $failed,
    ): InertiaResponse|JsonResponse {
        $snapshots = $metrics->snapshotsForJob($name);
        $avgMs = (int) round($metrics->runtimeForJob($name));

        // v2.2.0: real percentiles from the bucket histogram via
        // linear-interpolation across bucket boundaries. percentilesForJob()
        // returns ['p50' => int_ms, ...] and yields all-zeros when no jobs of
        // this class have completed yet — the stat tiles render 0 in that
        // case, which is correct.
        $percentiles = $metrics->percentilesForJob($name);
        $p50 = (int) ($percentiles['p50'] ?? 0);
        $p95 = (int) ($percentiles['p95'] ?? 0);
        $p99 = (int) ($percentiles['p99'] ?? 0);

        $runsLastHour = (int) array_sum(array_map(
            static fn ($s) => (int) ($s['throughput'] ?? 0),
            $snapshots,
        ));

        // v2.2.0: real per-job-runtime histogram. Falls back to a zero-filled
        // 6-bucket array when nothing has been recorded yet so the Vue page
        // still renders gracefully with empty bars.
        $histogram = $metrics->runtimeBucketsForJob($name);
        if ($histogram === []) {
            $histogram = $this->emptyHistogram();
        }

        // Per-class filter: match against the same fields the Recent.vue
        // jobName(row) helper checks (`name || display_name || type ||
        // job_class`). We don't extend the JobRepository contract; instead we
        // filter the small collection returned by getRecent()/getFailed() in
        // memory. That keeps the v1.x contract stable for downstream consumers.
        $matchesClass = static fn (object $row): bool => $row->name === $name
            || ($row->display_name ?? null) === $name
            || ($row->type ?? null) === $name
            || ($row->job_class ?? null) === $name;

        $recentRuns = $jobs->getRecent()
            ->filter($matchesClass)
            ->take(20)
            ->values()
            ->map(fn (object $row) => $this->mapRecentRun($row))
            ->all();

        $recentFailures = $failed->getFailed()
            ->filter($matchesClass)
            ->take(10)
            ->values()
            ->map(fn (object $row) => $this->mapRecentFailure($row))
            ->all();

        $failures1h = count($recentFailures);

        // Failure-rate denominator is total runs (including failures) for the
        // class over the snapshot window. Returns '—' when there's nothing to
        // divide against — matches the Overview controller's convention.
        $failureRatePct = $runsLastHour > 0
            ? (string) number_format(($failures1h / max(1, $runsLastHour)) * 100, 2, '.', '')
            : '—';

        $stats = [
            'runs_1h' => $runsLastHour,
            'avg_ms' => $avgMs,
            'p50_ms' => $p50,
            'p95_ms' => $p95,
            'p99_ms' => $p99,
            'failure_rate_pct' => $failureRatePct,
            'failures_1h' => $failures1h,
        ];

        $throughputSeries = array_map(
            static fn ($s) => [
                'ts' => (int) ($s['time'] ?? 0),
                'value' => (float) ($s['throughput'] ?? 0),
            ],
            $snapshots,
        );

        return $this->inertiaOrJson($request, 'Sunset/ClassDetail', [
            'class_name' => $name,
            'stats' => $stats,
            'throughput_series' => $throughputSeries,
            'runtime_histogram' => $histogram,
            'recent_runs' => $recentRuns,
            'recent_failures' => $recentFailures,
        ]);
    }

    /**
     * Project a job hash from JobRepository::getRecent() into the column
     * shape ClassDetail.vue's "Recent runs" DataTable expects: `at`, `queue`,
     * `runtime_ms`, `status`, `attempt`, `pid`, `tags`. The repo doesn't
     * record per-job runtimes/attempts/pid/tags today (see the caveat on
     * {@see class()}), so we surface what we have and leave the rest null —
     * the Vue table renders a `—` fallback for missing values.
     */
    private function mapRecentRun(object $row): array
    {
        $at = $row->completed_at
            ?? $row->reserved_at
            ?? $row->failed_at
            ?? null;

        return [
            'at' => $at !== null ? (int) $at : null,
            'queue' => $row->queue ?? null,
            'runtime_ms' => null,
            'status' => $row->status ?? null,
            'attempt' => null,
            'pid' => null,
            'tags' => null,
        ];
    }

    /**
     * Project a failed-job hash from FailedJobRepository::getFailed() into the
     * column shape ClassDetail.vue's "Recent failures" DataTable expects:
     * `failed_at`, `exception_class`, `message`, `attempts`. The exception
     * blob is stored as a JSON string by RedisFailedJobRepository::failed();
     * decode and pluck the class/message pair so the page renders something
     * useful instead of the raw JSON.
     */
    private function mapRecentFailure(object $row): array
    {
        $exception = $row->exception ?? null;
        $exceptionClass = null;
        $message = null;

        if (is_string($exception) && $exception !== '') {
            $decoded = json_decode($exception, true);
            if (is_array($decoded)) {
                $exceptionClass = $decoded['class'] ?? null;
                $message = $decoded['message'] ?? null;
            }
        }

        return [
            'failed_at' => isset($row->failed_at) ? (int) $row->failed_at : null,
            'exception_class' => $exceptionClass,
            'message' => $message,
            'attempts' => null,
        ];
    }

    /**
     * Zero-filled 6-bucket histogram matching the labels rendered by the
     * ClassDetail page. Used as a fallback when the repo hasn't recorded any
     * runtime observations yet (e.g. a fresh deployment, or a job class that
     * has never completed). Keeps the Vue page from rendering an empty list
     * — it expects exactly 6 entries.
     */
    private function emptyHistogram(): array
    {
        $labels = ['0–50 ms', '50–250 ms', '250–500 ms', '500 ms–1 s', '1–5 s', '5 s+'];
        $out = [];
        foreach ($labels as $i => $label) {
            $out[] = [
                'label'  => $label,
                'count'  => 0,
                'pct'    => 0.0,
                'danger' => false,
            ];
        }
        return $out;
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
