<?php

namespace Admnio\Sunset\Repositories\Redis;

use Admnio\Sunset\Contracts\MasterSupervisorRepository;
use Admnio\Sunset\Contracts\SupervisorRepository;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Arr;

/**
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on the published Admnio\Sunset\Contracts\* interfaces instead.
 */
class RedisMasterSupervisorRepository implements MasterSupervisorRepository
{
    public function __construct(private RedisFactory $redis) {}

    /**
     * Get the names of all the master supervisors currently running.
     */
    public function names(): array
    {
        return $this->connection()->zrevrangebyscore(
            $this->key('masters'),
            '+inf',
            CarbonImmutable::now()->subSeconds(14)->getTimestamp()
        );
    }

    /**
     * Get information on all of the master supervisors.
     */
    public function all(): array
    {
        return $this->get($this->names());
    }

    /**
     * Get information on a master supervisor by name.
     */
    public function find(string $name): ?array
    {
        return Arr::get($this->get([$name]), 0);
    }

    /**
     * Get information on the given master supervisors.
     */
    public function get(array $names): array
    {
        $records = $this->connection()->pipeline(function ($pipe) use ($names) {
            foreach ($names as $name) {
                $pipe->hmget($this->key("master:{$name}"), ['name', 'pid', 'status', 'supervisors', 'environment']);
            }
        });

        return collect($records)
            ->map(function ($record) {
                if (! is_array($record)) {
                    return null;
                }

                // phpredis returns an associative array keyed by field name;
                // predis returns a numerically-indexed array. Normalise to list.
                $record = array_values((array) $record);

                return ! $record[0] ? null : [
                    'name' => $record[0],
                    'pid' => $record[1],
                    'status' => $record[2],
                    'supervisors' => json_decode($record[3], true),
                    'environment' => $record[4],
                ];
            })
            ->filter()
            ->all();
    }

    /**
     * Update the information about the given master supervisor.
     *
     * NOTE: \Admnio\Sunset\Supervisor\MasterSupervisor is implemented in Task 13.
     * PHP defers class-existence checks to runtime, so this type hint is valid now.
     * Tests pass a Mockery mock of this class to avoid needing the real class.
     */
    public function update(\Admnio\Sunset\Supervisor\MasterSupervisor $master): void
    {
        $supervisors = $master->supervisors->map->name->all();

        $this->connection()->pipeline(function ($pipe) use ($master, $supervisors) {
            $pipe->hmset(
                $this->key("master:{$master->name}"),
                [
                    'name' => $master->name,
                    'environment' => $master->environment,
                    'pid' => $master->pid(),
                    'status' => $master->working ? 'running' : 'paused',
                    'supervisors' => json_encode($supervisors),
                ]
            );

            $pipe->zadd(
                $this->key('masters'),
                (float) CarbonImmutable::now()->getTimestamp(),
                $master->name
            );

            $pipe->expire($this->key("master:{$master->name}"), 15);
        });
    }

    /**
     * Remove the master supervisor information from storage.
     */
    public function forget(string $name): void
    {
        if (! $master = $this->find($name)) {
            return;
        }

        $supervisorNames = $master['supervisors'] ?? [];

        if (! empty($supervisorNames)) {
            app(SupervisorRepository::class)->forget($supervisorNames);
        }

        $this->connection()->del($this->key("master:{$name}"));
        $this->connection()->zrem($this->key('masters'), $name);
    }

    /**
     * Remove expired master supervisors from storage.
     */
    public function flushExpired(): void
    {
        $this->connection()->zremrangebyscore(
            $this->key('masters'),
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
