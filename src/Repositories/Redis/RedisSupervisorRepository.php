<?php

namespace Admnio\Sunset\Repositories\Redis;

use Admnio\Sunset\Contracts\SupervisorRepository;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Arr;

/**
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on the published Admnio\Sunset\Contracts\* interfaces instead.
 */
class RedisSupervisorRepository implements SupervisorRepository
{
    public function __construct(private RedisFactory $redis) {}

    /**
     * Get the names of all the supervisors currently running.
     */
    public function names(): array
    {
        return $this->connection()->zrevrangebyscore(
            $this->key('supervisors'),
            '+inf',
            CarbonImmutable::now()->subSeconds(29)->getTimestamp()
        );
    }

    /**
     * Get information on all of the supervisors.
     */
    public function all(): array
    {
        return $this->get($this->names());
    }

    /**
     * Get information on a supervisor by name.
     */
    public function find(string $name): ?array
    {
        return Arr::get($this->get([$name]), 0);
    }

    /**
     * Get information on the given supervisors.
     */
    public function get(array $names): array
    {
        $records = $this->connection()->pipeline(function ($pipe) use ($names) {
            foreach ($names as $name) {
                $pipe->hmget($this->key("supervisor:{$name}"), ['name', 'master', 'pid', 'status', 'processes', 'options']);
            }
        });

        return collect($records)
            ->filter()
            ->map(function ($record) {
                // phpredis returns an associative array keyed by field name;
                // predis returns a numerically-indexed array. Normalise to list.
                $record = array_values((array) $record);

                return ! $record[0] ? null : [
                    'name' => $record[0],
                    'master' => $record[1],
                    'pid' => $record[2],
                    'status' => $record[3],
                    'processes' => json_decode($record[4], true),
                    'options' => json_decode($record[5], true),
                ];
            })
            ->filter()
            ->all();
    }

    /**
     * Get the longest active timeout setting for a supervisor.
     */
    public function longestActiveTimeout(): int
    {
        return (int) (collect($this->all())
            ->max(fn ($supervisor) => $supervisor['options']['timeout'] ?? 0) ?: 0);
    }

    /**
     * Update the information about the given supervisor process.
     *
     * NOTE: \Admnio\Sunset\Supervisor\Supervisor is implemented in Task 12.
     * PHP defers class-existence checks to runtime, so this type hint is valid now.
     * Tests pass a Mockery mock of this class to avoid needing the real class.
     */
    public function update(\Admnio\Sunset\Supervisor\Supervisor $supervisor): void
    {
        $processes = $supervisor->processPools
            ->mapWithKeys(fn ($pool) => [$supervisor->options->connection . ':' . $pool->queue() => count($pool->processes())])
            ->toJson();

        $this->connection()->pipeline(function ($pipe) use ($supervisor, $processes) {
            $pipe->hmset(
                $this->key("supervisor:{$supervisor->name}"),
                [
                    'name' => $supervisor->name,
                    'master' => implode(':', explode(':', $supervisor->name, -1)),
                    'pid' => $supervisor->pid(),
                    'status' => $supervisor->working ? 'running' : 'paused',
                    'processes' => $processes,
                    'options' => $supervisor->options->toJson(),
                ]
            );

            $pipe->zadd(
                $this->key('supervisors'),
                (float) CarbonImmutable::now()->getTimestamp(),
                $supervisor->name
            );

            $pipe->expire($this->key("supervisor:{$supervisor->name}"), 30);
        });
    }

    /**
     * Remove the supervisor information from storage.
     */
    public function forget(array|string $names): void
    {
        $names = (array) $names;

        if (empty($names)) {
            return;
        }

        $this->connection()->del(
            ...collect($names)->map(fn ($name) => $this->key("supervisor:{$name}"))->all()
        );

        $this->connection()->zrem($this->key('supervisors'), ...$names);
    }

    /**
     * Remove expired supervisors from storage.
     */
    public function flushExpired(): void
    {
        $this->connection()->zremrangebyscore(
            $this->key('supervisors'),
            '-inf',
            CarbonImmutable::now()->subSeconds(14)->getTimestamp()
        );
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
