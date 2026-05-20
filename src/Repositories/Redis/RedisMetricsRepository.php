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
        $conn = $this->connection();
        $conn->pipeline(function ($pipe) use ($jobName, $queue, $runtime) {
            $pipe->sadd($this->key('measured_jobs'), $jobName);
            $pipe->sadd($this->key('measured_queues'), $queue);
            $pipe->hincrby($this->key("metrics:job:{$jobName}"), 'throughput', 1);
            $pipe->hincrbyfloat($this->key("metrics:job:{$jobName}"), 'runtime_sum', $runtime);
            $pipe->hincrby($this->key("metrics:queue:{$queue}"), 'throughput', 1);
            $pipe->hincrbyfloat($this->key("metrics:queue:{$queue}"), 'runtime_sum', $runtime);
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

    private function key(string $name): string
    {
        return config('sunset.key_prefix', 'sunset') . ':' . $name;
    }

    private function connection()
    {
        return $this->redis->connection(config('sunset.redis_connection', 'default'));
    }
}
