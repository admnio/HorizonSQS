<?php

namespace Admnio\Sunset\Repositories\Redis;

use Admnio\Sunset\Contracts\ProcessRepository;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Redis\Factory as RedisFactory;

/**
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on the published Admnio\Sunset\Contracts\* interfaces instead.
 */
class RedisProcessRepository implements ProcessRepository
{
    public function __construct(private RedisFactory $redis) {}

    /**
     * Get all of the orphan process IDs and the times they were observed.
     *
     * Returns a hash of PID => timestamp.
     */
    public function allOrphans(string $master): array
    {
        return $this->connection()->hgetall(
            $this->key("supervisor:{$master}:orphans")
        ) ?: [];
    }

    /**
     * Record the given process IDs as orphaned, removing any PIDs no longer
     * in the current list.
     *
     * Returns the full array of currently-tracked orphan PIDs.
     */
    public function orphaned(string $master, array $processIds): array
    {
        $time = CarbonImmutable::now()->getTimestamp();
        $orphansKey = $this->key("supervisor:{$master}:orphans");

        // Remove PIDs that are no longer considered orphaned
        $shouldRemove = array_diff(
            $this->connection()->hkeys($orphansKey),
            $processIds
        );

        if (! empty($shouldRemove)) {
            $this->connection()->hdel($orphansKey, ...$shouldRemove);
        }

        // Record new PIDs (hsetnx: only set if not already set, preserving the original timestamp)
        $this->connection()->pipeline(function ($pipe) use ($orphansKey, $time, $processIds) {
            foreach ($processIds as $processId) {
                $pipe->hsetnx($orphansKey, $processId, $time);
            }
        });

        return $processIds;
    }

    /**
     * Get the process IDs that have been orphaned for at least the given number of seconds.
     */
    public function orphanedFor(string $master, int $seconds): array
    {
        $expiresAt = CarbonImmutable::now()->getTimestamp() - $seconds;

        return collect($this->allOrphans($master))
            ->filter(fn ($recordedAt, $_) => $expiresAt > $recordedAt)
            ->keys()
            ->all();
    }

    /**
     * Remove the given process IDs from the orphan list.
     */
    public function forgetOrphans(string $master, array $processIds): void
    {
        if (empty($processIds)) {
            return;
        }

        $this->connection()->hdel(
            $this->key("supervisor:{$master}:orphans"),
            ...$processIds
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
