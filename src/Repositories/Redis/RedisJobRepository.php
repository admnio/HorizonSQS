<?php

namespace Admnio\Sunset\Repositories\Redis;

use Admnio\Sunset\Contracts\JobRepository;
use Admnio\Sunset\JobPayload;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Collection;

/**
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on the published Admnio\Sunset\Contracts\* interfaces instead.
 */
class RedisJobRepository implements JobRepository
{
    public array $keys = [
        'id', 'connection', 'queue', 'name', 'status', 'payload',
        'exception', 'context', 'failed_at', 'completed_at', 'retried_by',
        'reserved_at', 'delay',
    ];

    public int $recentJobExpires;
    public int $pendingJobExpires;
    public int $completedJobExpires;
    public int $failedJobExpires;
    public int $recentFailedJobExpires;
    public int $monitoredJobExpires;

    public function __construct(private RedisFactory $redis)
    {
        $this->recentJobExpires        = (int) config('sunset.trim.recent', 60);
        $this->pendingJobExpires       = (int) config('sunset.trim.pending', 60);
        $this->completedJobExpires     = (int) config('sunset.trim.completed', 60);
        $this->failedJobExpires        = (int) config('sunset.trim.failed', 10080);
        $this->recentFailedJobExpires  = (int) config('sunset.trim.recent_failed', $this->failedJobExpires);
        $this->monitoredJobExpires     = (int) config('sunset.trim.monitored', 10080);
    }

    public function nextJobId(): string
    {
        return (string) $this->connection()->incr($this->key('job_id'));
    }

    public function totalRecent(): int
    {
        return (int) $this->connection()->zcard($this->key('recent_jobs'));
    }

    public function pushed(string $connection, string $queue, JobPayload $payload): void
    {
        $time = (float) CarbonImmutable::now()->getPreciseTimestamp(3);

        $this->connection()->pipeline(function ($pipe) use ($payload, $connection, $queue, $time) {
            $pipe->zadd($this->key('recent_jobs'), $time, $payload->id());
            $pipe->zadd($this->key('pending_jobs'), $time, $payload->id());
            $pipe->hmset($this->key("job:{$payload->id()}"), [
                'id' => $payload->id(),
                'connection' => $connection,
                'queue' => $queue,
                'name' => $payload->decoded['displayName'] ?? '',
                'status' => 'pending',
                'payload' => $payload->value,
            ]);
            $pipe->expireat($this->key("job:{$payload->id()}"),
                CarbonImmutable::now()->addMinutes($this->pendingJobExpires)->getTimestamp());
        });
    }

    public function reserved(string $connection, string $queue, JobPayload $payload): void
    {
        $this->connection()->hmset($this->key("job:{$payload->id()}"), [
            'status' => 'reserved',
            'reserved_at' => CarbonImmutable::now()->getTimestamp(),
        ]);
    }

    public function released(string $connection, string $queue, JobPayload $payload, int $delay = 0): void
    {
        $this->connection()->hmset($this->key("job:{$payload->id()}"), [
            'status' => 'pending',
            'delay' => $delay,
        ]);
    }

    public function completed(JobPayload $payload, bool $silenced = false): void
    {
        $time = (float) CarbonImmutable::now()->getPreciseTimestamp(3);
        $indexKey = $silenced ? $this->key('silenced_jobs') : $this->key('completed_jobs');

        $this->connection()->pipeline(function ($pipe) use ($payload, $time, $indexKey) {
            $pipe->zrem($this->key('pending_jobs'), $payload->id());
            $pipe->zadd($indexKey, $time, $payload->id());
            $pipe->hmset($this->key("job:{$payload->id()}"), [
                'status' => 'completed',
                'completed_at' => CarbonImmutable::now()->getTimestamp(),
            ]);
            $pipe->expireat($this->key("job:{$payload->id()}"),
                CarbonImmutable::now()->addMinutes($this->completedJobExpires)->getTimestamp());
        });
    }

    public function remember(string $connection, string $queue, JobPayload $payload): void
    {
        $time = (float) CarbonImmutable::now()->getPreciseTimestamp(3);

        $this->connection()->pipeline(function ($pipe) use ($payload, $connection, $queue, $time) {
            $pipe->zadd($this->key('monitored_jobs'), $time, $payload->id());
            $pipe->hmset($this->key("job:{$payload->id()}"), [
                'id' => $payload->id(),
                'connection' => $connection,
                'queue' => $queue,
                'name' => $payload->decoded['displayName'] ?? '',
                'status' => 'completed',
                'payload' => $payload->value,
            ]);
            $pipe->expireat($this->key("job:{$payload->id()}"),
                CarbonImmutable::now()->addMinutes($this->monitoredJobExpires)->getTimestamp());
        });
    }

    public function migrated(string $connection, string $queue, Collection $payloads): void
    {
        $time = CarbonImmutable::now()->getPreciseTimestamp(3);

        $this->connection()->pipeline(function ($pipe) use ($payloads, $connection, $queue, $time) {
            foreach ($payloads as $payload) {
                $pipe->zadd($this->key('recent_jobs'), (float) $time, $payload->id());
                $pipe->zadd($this->key('pending_jobs'), (float) $time, $payload->id());
                $pipe->hmset($this->key("job:{$payload->id()}"), [
                    'id' => $payload->id(),
                    'connection' => $connection,
                    'queue' => $queue,
                    'name' => $payload->decoded['displayName'] ?? '',
                    'status' => 'pending',
                    'payload' => $payload->value,
                ]);
                $pipe->expireat($this->key("job:{$payload->id()}"),
                    CarbonImmutable::now()->addMinutes($this->pendingJobExpires)->getTimestamp());
            }
        });
    }

    public function getRecent(?string $afterIndex = null): Collection
    {
        return $this->paginate($this->key('recent_jobs'), $afterIndex);
    }

    public function getPending(?string $afterIndex = null): Collection
    {
        return $this->paginate($this->key('pending_jobs'), $afterIndex);
    }

    public function getCompleted(?string $afterIndex = null): Collection
    {
        return $this->paginate($this->key('completed_jobs'), $afterIndex);
    }

    public function getSilenced(?string $afterIndex = null): Collection
    {
        return $this->paginate($this->key('silenced_jobs'), $afterIndex);
    }

    public function getJobs(array $ids, int|string $indexFrom = 0): Collection
    {
        $hashes = $this->connection()->pipeline(function ($pipe) use ($ids) {
            foreach ($ids as $id) {
                $pipe->hmget($this->key("job:{$id}"), $this->keys);
            }
        });

        return collect($hashes)
            ->map(function ($values, $i) use ($ids, $indexFrom) {
                // phpredis returns an associative array keyed by field name;
                // predis returns a numerically-indexed array. Normalise to list.
                $list = array_values((array) $values);
                if (empty($list) || $list[0] === false || $list[0] === null) {
                    return null;
                }
                $job = (object) array_combine($this->keys, $list);
                $job->index = is_int($indexFrom) ? $indexFrom + $i : null;
                return $job;
            })
            ->filter()
            ->values();
    }

    public function countRecent(): int       { return (int) $this->connection()->zcard($this->key('recent_jobs')); }
    public function countPending(): int      { return (int) $this->connection()->zcard($this->key('pending_jobs')); }
    public function countCompleted(): int    { return (int) $this->connection()->zcard($this->key('completed_jobs')); }
    public function countSilenced(): int     { return (int) $this->connection()->zcard($this->key('silenced_jobs')); }

    public function trimRecentJobs(): void
    {
        $this->trim($this->key('recent_jobs'), $this->recentJobExpires);
    }

    public function trimMonitoredJobs(): void
    {
        $this->trim($this->key('monitored_jobs'), $this->monitoredJobExpires);
    }

    public function deleteMonitored(array $ids): void
    {
        $this->connection()->pipeline(function ($pipe) use ($ids) {
            foreach ($ids as $id) {
                $pipe->zrem($this->key('monitored_jobs'), $id);
                $pipe->del($this->key("job:{$id}"));
            }
        });
    }

    public function storeRetryReference(string $id, string $retryId): void
    {
        $conn = $this->connection();
        $existing = $conn->hget($this->key("job:{$id}"), 'retried_by');
        $decoded = $existing ? json_decode($existing, true) : [];
        $decoded[] = ['id' => $retryId, 'status' => 'pending', 'retried_at' => CarbonImmutable::now()->getTimestamp()];
        $conn->hset($this->key("job:{$id}"), 'retried_by', json_encode($decoded));
    }

    private function paginate(string $indexKey, ?string $afterIndex): Collection
    {
        $afterIndex = $afterIndex !== null ? (int) $afterIndex : -1;
        $start = $afterIndex + 1;
        $stop = $start + 49;
        $ids = $this->connection()->zrevrange($indexKey, $start, $stop);
        return $this->getJobs((array) $ids, $start);
    }

    private function trim(string $indexKey, int $minutes): void
    {
        $cutoff = (float) CarbonImmutable::now()->subMinutes($minutes)->getPreciseTimestamp(3);
        $this->connection()->zremrangebyscore($indexKey, '-inf', $cutoff);
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
