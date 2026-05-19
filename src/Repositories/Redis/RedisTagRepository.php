<?php

namespace Admnio\Sunset\Repositories\Redis;

use Admnio\Sunset\Contracts\TagRepository;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Collection;

class RedisTagRepository implements TagRepository
{
    public function __construct(private RedisFactory $redis)
    {
    }

    public function jobs(string $tag, ?string $afterIndex = null): Collection
    {
        $afterIndex = $afterIndex !== null ? (int) $afterIndex : -1;
        $start = $afterIndex + 1;
        $stop = $start + 49;
        $ids = (array) $this->connection()->zrevrange($this->key("tag:{$tag}"), $start, $stop);
        return collect($ids);
    }

    public function paginate(string $tag, ?string $afterIndex = null): array
    {
        return [
            'jobs' => $this->jobs($tag, $afterIndex)->all(),
            'total' => $this->count($tag),
        ];
    }

    public function count(string $tag): int
    {
        return (int) $this->connection()->zcard($this->key("tag:{$tag}"));
    }

    public function addTemporary(int $expiresAt, string $jobId, array $tags): void
    {
        $time = (float) CarbonImmutable::now()->getPreciseTimestamp(3);
        $this->connection()->pipeline(function ($pipe) use ($expiresAt, $jobId, $tags, $time) {
            foreach ($tags as $tag) {
                $pipe->zadd($this->key("tag:{$tag}"), $time, $jobId);
                $pipe->expireat($this->key("tag:{$tag}"), $expiresAt);
            }
            if (! empty($tags)) {
                $pipe->sadd($this->key("job:{$jobId}:tags"), ...$tags);
                $pipe->expireat($this->key("job:{$jobId}:tags"), $expiresAt);
            }
        });
    }

    public function addPermanent(string $jobId, array $tags): void
    {
        $time = (float) CarbonImmutable::now()->getPreciseTimestamp(3);
        $this->connection()->pipeline(function ($pipe) use ($jobId, $tags, $time) {
            foreach ($tags as $tag) {
                $pipe->zadd($this->key("tag:{$tag}"), $time, $jobId);
            }
            if (! empty($tags)) {
                $pipe->sadd($this->key("job:{$jobId}:tags"), ...$tags);
            }
        });
    }

    public function forJobs(array $jobIds): array
    {
        if (empty($jobIds)) {
            return [];
        }
        $results = $this->connection()->pipeline(function ($pipe) use ($jobIds) {
            foreach ($jobIds as $id) {
                $pipe->smembers($this->key("job:{$id}:tags"));
            }
        });
        return array_combine($jobIds, array_map(fn ($r) => (array) ($r ?: []), $results));
    }

    public function monitor(string $tag): void
    {
        $this->connection()->sadd($this->key('monitored_tags'), $tag);
    }

    public function stopMonitoring(string $tag): void
    {
        $this->connection()->srem($this->key('monitored_tags'), $tag);
    }

    public function isMonitoring(string $tag): bool
    {
        return (bool) $this->connection()->sismember($this->key('monitored_tags'), $tag);
    }

    public function monitored(): array
    {
        return (array) $this->connection()->smembers($this->key('monitored_tags'));
    }

    public function forget(string $tag): void
    {
        $conn = $this->connection();
        $jobIds = (array) $conn->zrange($this->key("tag:{$tag}"), 0, -1);
        $conn->pipeline(function ($pipe) use ($tag, $jobIds) {
            $pipe->del($this->key("tag:{$tag}"));
            foreach ($jobIds as $id) {
                $pipe->srem($this->key("job:{$id}:tags"), $tag);
            }
        });
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
