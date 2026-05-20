<?php

namespace Admnio\Sunset\Repositories\Redis;

use Admnio\Sunset\Contracts\FailedJobRepository;
use Admnio\Sunset\JobPayload;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Collection;
use Throwable;

/**
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on the published Admnio\Sunset\Contracts\* interfaces instead.
 */
class RedisFailedJobRepository implements FailedJobRepository
{
    public array $keys = [
        'id', 'connection', 'queue', 'name', 'status', 'payload',
        'exception', 'context', 'failed_at',
    ];

    public int $failedJobExpires;
    public int $recentFailedJobExpires;

    public function __construct(private RedisFactory $redis)
    {
        $this->failedJobExpires       = (int) config('sunset.trim.failed', 10080);
        $this->recentFailedJobExpires = (int) config('sunset.trim.recent_failed', $this->failedJobExpires);
    }

    public function failed(Throwable $e, string $connection, string $queue, JobPayload $payload): void
    {
        $time = (float) CarbonImmutable::now()->getPreciseTimestamp(3);
        $exception = json_encode([
            'class' => get_class($e),
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ]);

        $this->connection()->pipeline(function ($pipe) use ($payload, $connection, $queue, $exception, $time) {
            $pipe->zadd($this->key('failed_jobs'), $time, $payload->id());
            $pipe->zadd($this->key('recent_failed_jobs'), $time, $payload->id());
            $pipe->hmset($this->key("job:{$payload->id()}"), [
                'id' => $payload->id(),
                'connection' => $connection,
                'queue' => $queue,
                'name' => $payload->decoded['displayName'] ?? '',
                'status' => 'failed',
                'payload' => $payload->value,
                'exception' => $exception,
                'context' => '',
                'failed_at' => CarbonImmutable::now()->getTimestamp(),
            ]);
            $pipe->expireat($this->key("job:{$payload->id()}"),
                CarbonImmutable::now()->addMinutes($this->failedJobExpires)->getTimestamp());
        });
    }

    public function findFailed(string $id): ?object
    {
        $values = $this->connection()->hmget($this->key("job:{$id}"), $this->keys);
        // phpredis-6 returns an associative array keyed by field name; normalise to list.
        $list = array_values((array) $values);
        if (empty($list) || $list[0] === false || $list[0] === null) {
            return null;
        }
        $job = (object) array_combine($this->keys, $list);
        if ($job->status !== 'failed') {
            return null;
        }
        return $job;
    }

    public function getFailed(?string $afterIndex = null): Collection
    {
        $afterIndex = $afterIndex !== null ? (int) $afterIndex : -1;
        $start = $afterIndex + 1;
        $stop = $start + 49;
        $ids = (array) $this->connection()->zrevrange($this->key('failed_jobs'), $start, $stop);

        $hashes = $this->connection()->pipeline(function ($pipe) use ($ids) {
            foreach ($ids as $id) {
                $pipe->hmget($this->key("job:{$id}"), $this->keys);
            }
        });

        return collect($hashes)
            ->map(function ($values, $i) use ($start) {
                // phpredis-6 returns an associative array keyed by field name; normalise to list.
                $list = array_values((array) $values);
                if (empty($list) || $list[0] === false || $list[0] === null) {
                    return null;
                }
                $job = (object) array_combine($this->keys, $list);
                $job->index = $start + $i;
                return $job;
            })
            ->filter()
            ->values();
    }

    public function countFailed(): int          { return (int) $this->connection()->zcard($this->key('failed_jobs')); }
    public function totalFailed(): int          { return $this->countFailed(); }
    public function countRecentlyFailed(): int  { return (int) $this->connection()->zcard($this->key('recent_failed_jobs')); }

    public function deleteFailed(string $id): int
    {
        $conn = $this->connection();
        $removed = (int) $conn->zrem($this->key('failed_jobs'), $id);
        $conn->zrem($this->key('recent_failed_jobs'), $id);
        $conn->del($this->key("job:{$id}"));
        return $removed;
    }

    public function trimFailedJobs(): void
    {
        $cutoff = (float) CarbonImmutable::now()->subMinutes($this->failedJobExpires)->getPreciseTimestamp(3);
        $this->connection()->zremrangebyscore($this->key('failed_jobs'), '-inf', $cutoff);

        $recentCutoff = (float) CarbonImmutable::now()->subMinutes($this->recentFailedJobExpires)->getPreciseTimestamp(3);
        $this->connection()->zremrangebyscore($this->key('recent_failed_jobs'), '-inf', $recentCutoff);
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
