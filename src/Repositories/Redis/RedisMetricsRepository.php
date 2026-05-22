<?php

namespace Admnio\Sunset\Repositories\Redis;

use Admnio\Sunset\Contracts\MetricsRepository;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Redis\Factory as RedisFactory;

/**
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on the published Admnio\Sunset\Contracts\* interfaces instead.
 */
class RedisMetricsRepository implements MetricsRepository
{
    public function __construct(private RedisFactory $redis)
    {
    }

    public function jobs(): array
    {
        return (array) $this->connection()->smembers($this->key('measured_jobs'));
    }

    public function queues(): array
    {
        return (array) $this->connection()->smembers($this->key('measured_queues'));
    }

    public function throughputForJob(string $job): int
    {
        return (int) ($this->connection()->hget($this->key("metrics:job:{$job}"), 'throughput') ?? 0);
    }

    public function throughputForQueue(string $queue): int
    {
        return (int) ($this->connection()->hget($this->key("metrics:queue:{$queue}"), 'throughput') ?? 0);
    }

    public function runtimeForJob(string $job): float
    {
        return (float) ($this->connection()->hget($this->key("metrics:job:{$job}"), 'runtime') ?? 0);
    }

    public function runtimeForQueue(string $queue): float
    {
        return (float) ($this->connection()->hget($this->key("metrics:queue:{$queue}"), 'runtime') ?? 0);
    }

    public function snapshotsForJob(string $job): array
    {
        return $this->decodeSnapshots(
            (array) $this->connection()->zrange($this->key("metrics:snapshot:job:{$job}"), 0, -1)
        );
    }

    public function snapshotsForQueue(string $queue): array
    {
        return $this->decodeSnapshots(
            (array) $this->connection()->zrange($this->key("metrics:snapshot:queue:{$queue}"), 0, -1)
        );
    }

    public function incrementThroughput(string $jobName, string $queue, float $runtime): void
    {
        // v2.2.0: also record a per-class 6-bucket histogram so the ClassDetail
        // page can render real per-job-runtime percentiles + histograms instead
        // of deriving them from snapshot averages (see v2.1.0 caveat). Runtime
        // arrives in seconds (microtime() delta in MarkJobAsComplete); the
        // bucket boundaries are in ms, so convert before bucketing.
        $bucket = $this->bucketFor($runtime * 1000.0);

        $conn = $this->connection();
        $conn->pipeline(function ($pipe) use ($jobName, $queue, $runtime, $bucket) {
            $pipe->sadd($this->key('measured_jobs'), $jobName);
            $pipe->sadd($this->key('measured_queues'), $queue);
            $pipe->hincrby($this->key("metrics:job:{$jobName}"), 'throughput', 1);
            $pipe->hincrbyfloat($this->key("metrics:job:{$jobName}"), 'runtime_sum', $runtime);
            $pipe->hincrby($this->key("metrics:queue:{$queue}"), 'throughput', 1);
            $pipe->hincrbyfloat($this->key("metrics:queue:{$queue}"), 'runtime_sum', $runtime);
            $pipe->hincrby($this->key("metrics:job:{$jobName}:buckets"), 'b' . $bucket, 1);
        });

        // Compute and cache the mean runtime for fast reads.
        $this->cacheMean($this->key("metrics:job:{$jobName}"));
        $this->cacheMean($this->key("metrics:queue:{$queue}"));
    }

    public function acquireWaitTimes(): array
    {
        return (array) ($this->connection()->hgetall($this->key('wait')) ?: []);
    }

    public function forgetJob(string $job): void
    {
        $conn = $this->connection();
        $conn->srem($this->key('measured_jobs'), $job);
        $conn->del($this->key("metrics:job:{$job}"));
        $conn->del($this->key("metrics:snapshot:job:{$job}"));
        // v2.2.0: per-class runtime bucket histogram lives under its own key —
        // sweep it alongside the throughput/runtime hashes to keep the keyspace
        // tidy after operator-initiated forgets.
        $conn->del($this->key("metrics:job:{$job}:buckets"));
    }

    public function forgetQueue(string $queue): void
    {
        $conn = $this->connection();
        $conn->srem($this->key('measured_queues'), $queue);
        $conn->del($this->key("metrics:queue:{$queue}"));
        $conn->del($this->key("metrics:snapshot:queue:{$queue}"));
    }

    public function snapshot(): void
    {
        $time = CarbonImmutable::now()->getTimestamp();

        foreach ($this->jobs() as $job) {
            $this->writeSnapshot("metrics:snapshot:job:{$job}", $this->key("metrics:job:{$job}"), $time);
        }
        foreach ($this->queues() as $queue) {
            $this->writeSnapshot("metrics:snapshot:queue:{$queue}", $this->key("metrics:queue:{$queue}"), $time);
        }

        $this->connection()->set($this->key('last_snapshot_at'), $time);
    }

    public function latestSnapshotAt(): int
    {
        return (int) ($this->connection()->get($this->key('last_snapshot_at')) ?? 0);
    }

    public function acquireWaitTimeLock(int $ttlSeconds = 60): bool
    {
        $result = $this->connection()->set($this->key('wait-time-lock'), '1', 'EX', $ttlSeconds, 'NX');
        return $result === true || $result === 'OK';
    }

    private function writeSnapshot(string $snapshotKey, string $metricsKey, int $time): void
    {
        $conn = $this->connection();
        $data = $conn->hgetall($metricsKey) ?: [];
        if (empty($data)) {
            return;
        }
        $entry = json_encode([
            'time' => $time,
            'throughput' => (int) ($data['throughput'] ?? 0),
            'runtime' => (float) ($data['runtime'] ?? 0),
        ]);
        $conn->zadd($this->key($snapshotKey), (float) $time, $entry);

        // Keep only the last 24 snapshots per series (rolling window).
        $conn->zremrangebyrank($this->key($snapshotKey), 0, -25);

        // Reset the per-interval counters after a snapshot.
        $conn->hset($metricsKey, 'throughput', 0);
        $conn->hset($metricsKey, 'runtime_sum', 0);
    }

    private function cacheMean(string $metricsKey): void
    {
        $conn = $this->connection();
        $tp = (int) ($conn->hget($metricsKey, 'throughput') ?? 0);
        $sum = (float) ($conn->hget($metricsKey, 'runtime_sum') ?? 0);
        $mean = $tp > 0 ? $sum / $tp : 0.0;
        $conn->hset($metricsKey, 'runtime', $mean);
    }

    private function decodeSnapshots(array $entries): array
    {
        return array_values(array_filter(array_map(
            fn ($e) => $e ? json_decode($e, true) : null,
            $entries
        )));
    }

    /**
     * Per-class 6-bucket runtime histogram. Bucket counts since the metrics
     * keyspace was last flushed or trimmed. Returns an array indexed 0..5 of
     * ['label' => string, 'count' => int, 'pct' => float, 'danger' => bool]
     * with labels matching the ClassDetail page's expected format.
     *
     * Note: NOT on the MetricsRepository contract — Sunset's stable public
     * interface is intentionally narrow. Use this concrete method only from
     * controllers that explicitly depend on the Redis implementation.
     */
    public function runtimeBucketsForJob(string $job): array
    {
        $hash = (array) ($this->connection()->hgetall($this->key("metrics:job:{$job}:buckets")) ?: []);

        $layout = $this->bucketLayout();
        $counts = [];
        $total = 0;
        foreach ($layout as $i => $_) {
            $c = (int) ($hash['b' . $i] ?? 0);
            $counts[$i] = $c;
            $total += $c;
        }

        $out = [];
        foreach ($layout as $i => $b) {
            $count = $counts[$i];
            $pct = $total > 0 ? round(($count / $total) * 100, 1) : 0.0;
            $out[] = [
                'label'  => $b['label'],
                'count'  => $count,
                'pct'    => $pct,
                // The "5 s+" tail bucket is the danger band — light up only
                // when we actually have observations there. Matches the
                // ClassDetail page's existing convention.
                'danger' => $b['min'] >= 5000 && $count > 0,
            ];
        }

        return $out;
    }

    /**
     * Approximate percentiles derived from the bucket histogram. More accurate
     * than the snapshot-average derivation, less accurate than a full sample
     * reservoir. Uses linear interpolation across bucket boundaries — finds
     * the bucket that contains the Nth-percentile job, then interpolates by
     * (target_count_within_bucket / count_in_bucket) * bucket_width + bucket_lower.
     *
     * Returns ['p50' => int_ms, 'p95' => int_ms, 'p99' => int_ms].
     *
     * Note: NOT on the MetricsRepository contract — concrete-only method that
     * leans on the bucket layout owned by this repository.
     */
    public function percentilesForJob(string $job): array
    {
        $hash = (array) ($this->connection()->hgetall($this->key("metrics:job:{$job}:buckets")) ?: []);

        $layout = $this->bucketLayout();
        $counts = [];
        $total = 0;
        foreach ($layout as $i => $_) {
            $c = (int) ($hash['b' . $i] ?? 0);
            $counts[$i] = $c;
            $total += $c;
        }

        if ($total === 0) {
            return ['p50' => 0, 'p95' => 0, 'p99' => 0];
        }

        return [
            'p50' => $this->interpolatePercentile($counts, $layout, $total, 0.50),
            'p95' => $this->interpolatePercentile($counts, $layout, $total, 0.95),
            'p99' => $this->interpolatePercentile($counts, $layout, $total, 0.99),
        ];
    }

    /**
     * Map a runtime in milliseconds to the bucket index 0..5 matching the
     * fixed layout the ClassDetail page renders. Buckets use [lower, upper)
     * semantics; the final 5 s+ tail bucket is open-ended on the right.
     */
    private function bucketFor(float $runtimeMs): int
    {
        // Guard against tiny negatives from clock drift in microtime() deltas.
        if ($runtimeMs < 50.0)   return 0;
        if ($runtimeMs < 250.0)  return 1;
        if ($runtimeMs < 500.0)  return 2;
        if ($runtimeMs < 1000.0) return 3;
        if ($runtimeMs < 5000.0) return 4;
        return 5;
    }

    /**
     * Canonical 6-bucket layout. Centralized here so {@see bucketFor()},
     * {@see runtimeBucketsForJob()}, and {@see percentilesForJob()} cannot
     * drift apart — the controller used to keep its own copy of this list.
     *
     * @return list<array{label:string,min:int,max:int}>
     */
    private function bucketLayout(): array
    {
        return [
            ['label' => '0–50 ms',    'min' => 0,    'max' => 50],
            ['label' => '50–250 ms',  'min' => 50,   'max' => 250],
            ['label' => '250–500 ms', 'min' => 250,  'max' => 500],
            ['label' => '500 ms–1 s', 'min' => 500,  'max' => 1000],
            ['label' => '1–5 s',      'min' => 1000, 'max' => 5000],
            ['label' => '5 s+',       'min' => 5000, 'max' => PHP_INT_MAX],
        ];
    }

    /**
     * Linear-interpolation percentile across the bucket histogram.
     *
     * Standard histogram-percentile formula: find the bucket containing the
     * Nth-percentile sample (cumulative count >= q * total), then estimate the
     * sample's position inside that bucket by linear interpolation:
     *
     *   target          = q * total
     *   before          = cumulative count before this bucket
     *   in_bucket_rank  = target - before          // 0..count_in_bucket
     *   fraction        = in_bucket_rank / count_in_bucket
     *   estimate_ms     = bucket_min + fraction * (bucket_max - bucket_min)
     *
     * For the open-ended tail bucket we cap bucket_max at a representative
     * upper bound (10s) so p99 of a workload concentrated in the 5s+ bucket
     * returns a finite value the UI can render.
     *
     * @param array<int,int> $counts
     * @param list<array{label:string,min:int,max:int}> $layout
     */
    private function interpolatePercentile(array $counts, array $layout, int $total, float $q): int
    {
        $target = $q * $total;
        $cum = 0;
        $lastNonEmpty = 0;

        foreach ($layout as $i => $b) {
            $c = $counts[$i] ?? 0;
            if ($c === 0) {
                continue;
            }
            $lastNonEmpty = $i;
            $before = $cum;
            $cum += $c;
            if ($cum >= $target) {
                $min = (int) $b['min'];
                // Cap the open-ended tail bucket so interpolation stays finite.
                $max = $b['max'] === PHP_INT_MAX ? 10000 : (int) $b['max'];
                $width = max(1, $max - $min);
                $rankInBucket = max(0.0, $target - $before);
                $fraction = $c > 0 ? $rankInBucket / $c : 0.0;
                return (int) round($min + $fraction * $width);
            }
        }

        // All counts processed without hitting target — should only happen on
        // rounding edges. Fall back to the upper edge of the last bucket that
        // actually held samples.
        $b = $layout[$lastNonEmpty];
        return (int) ($b['max'] === PHP_INT_MAX ? 10000 : $b['max']);
    }

    private function key(string $name): string
    {
        return config('sunset.key_prefix', 'sunset') . ':' . $name;
    }

    private function connection()
    {
        return $this->redis->connection(config('sunset.redis_connection', 'default'));
    }
}
