<?php

namespace Admnio\Sunset\Dashboard\Http\Controllers;

use Admnio\Sunset\Contracts\FailedJobRepository;
use Admnio\Sunset\Contracts\JobRepository;
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

    /**
     * v2.0 — detail page for a single job class. Renders the Inertia
     * ClassDetail page with stats, throughput series, histogram, and
     * recent runs/failures.
     *
     * Percentiles + histogram are derived from the per-snapshot runtime
     * series the repo already records (see {@see realHistogram()} and the
     * inline percentile math below). They are accurate within the bounds
     * of that data — see the caveat in {@see realHistogram()}.
     */
    public function class(
        string $name,
        Request $request,
        MetricsRepository $metrics,
        JobRepository $jobs,
        FailedJobRepository $failed,
    ): InertiaResponse|JsonResponse {
        $snapshots = $metrics->snapshotsForJob($name);
        $avgMs = (int) round($metrics->runtimeForJob($name));

        // Derive percentiles from the per-snapshot average runtime values.
        // Caveat: these are percentiles over snapshot averages, not over
        // individual job runtimes — the repo doesn't record per-job timings,
        // so this is the best fidelity we can offer without a contract bump.
        // For a workload with low variance across snapshots the difference
        // is negligible; for bursty workloads the p99 will under-report.
        $runtimeValues = array_values(array_filter(array_map(
            static fn ($s) => (float) ($s['runtime'] ?? 0),
            $snapshots,
        ), static fn ($v) => $v > 0.0));

        [$p50, $p95, $p99] = $this->percentiles($runtimeValues, $avgMs);

        $runsLastHour = (int) array_sum(array_map(
            static fn ($s) => (int) ($s['throughput'] ?? 0),
            $snapshots,
        ));

        $histogram = $this->realHistogram($snapshots);

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
     * Compute [p50, p95, p99] as integer millisecond values from the given
     * runtime samples. When no samples are available we fall back to the
     * stored mean runtime so the stat tiles still render a non-zero value
     * for jobs that have run but not yet been snapshotted.
     *
     * @param list<float> $values
     * @return array{0:int,1:int,2:int}
     */
    private function percentiles(array $values, int $avgMs): array
    {
        if ($values === []) {
            return [$avgMs, $avgMs, $avgMs];
        }
        sort($values, SORT_NUMERIC);
        return [
            $this->percentile($values, 0.50),
            $this->percentile($values, 0.95),
            $this->percentile($values, 0.99),
        ];
    }

    /**
     * Nearest-rank percentile over an already-sorted list of values.
     *
     * @param list<float> $sorted
     */
    private function percentile(array $sorted, float $q): int
    {
        $n = count($sorted);
        if ($n === 0) {
            return 0;
        }
        // Nearest-rank with clamping — keeps the boundary cases (q=0, q=1)
        // exact and avoids the off-by-one ambiguity of interpolation methods.
        $idx = (int) ceil($q * $n) - 1;
        if ($idx < 0) {
            $idx = 0;
        }
        if ($idx >= $n) {
            $idx = $n - 1;
        }
        return (int) round($sorted[$idx]);
    }

    /**
     * Bucket the snapshot runtime values into the six fixed buckets used by
     * the ClassDetail Vue page. Each snapshot contributes one observation
     * (its average runtime over the snapshot window), so this is a histogram
     * of snapshot-averages, not of individual job runtimes — see the caveat
     * in {@see class()}.
     *
     * @param array<int, array<string, mixed>> $snapshots
     */
    private function realHistogram(array $snapshots): array
    {
        $buckets = [
            ['label' => '0–50 ms', 'min' => 0, 'max' => 50],
            ['label' => '50–250 ms', 'min' => 50, 'max' => 250],
            ['label' => '250–500 ms', 'min' => 250, 'max' => 500],
            ['label' => '500 ms–1 s', 'min' => 500, 'max' => 1000],
            ['label' => '1–5 s', 'min' => 1000, 'max' => 5000],
            ['label' => '5 s+', 'min' => 5000, 'max' => PHP_INT_MAX],
        ];

        $counts = array_fill(0, count($buckets), 0);
        $total  = 0;
        foreach ($snapshots as $s) {
            $rt = (float) ($s['runtime'] ?? 0);
            if ($rt <= 0.0) {
                continue;
            }
            foreach ($buckets as $i => $b) {
                if ($rt >= $b['min'] && $rt < $b['max']) {
                    $counts[$i]++;
                    $total++;
                    break;
                }
            }
        }

        $out = [];
        foreach ($buckets as $i => $b) {
            $count = $counts[$i];
            $pct   = $total > 0 ? round(($count / $total) * 100, 1) : 0.0;
            $out[] = [
                'label'  => $b['label'],
                'count'  => $count,
                'pct'    => $pct,
                'danger' => $b['min'] >= 5000 && $count > 0,
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
