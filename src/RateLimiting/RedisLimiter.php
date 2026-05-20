<?php

namespace Admnio\Sunset\RateLimiting;

use Admnio\Sunset\Contracts\Limiter;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Str;

/**
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on the published Admnio\Sunset\Contracts\* interfaces instead.
 */
class RedisLimiter implements Limiter
{
    private const KEY_PREFIX = 'sunset:rl';

    public function __construct(
        private RedisFactory $redis,
        private string $connectionName,
    ) {
    }

    public function check(Limit $limit, string $bucketKey): Decision
    {
        $conn = $this->redis->connection($this->connectionName);
        $reservations = [];

        if ($limit->throttle !== null) {
            $key = self::KEY_PREFIX . ':t:' . $limit->name . ':' . $bucketKey;
            $entry = Str::uuid()->toString();
            [$ok, $payload] = $conn->eval(
                $this->throttleScript(),
                1,
                $key,
                time(),
                $limit->throttle->windowSeconds,
                $limit->throttle->max,
                $entry,
            );
            if ((int) $ok !== 1) {
                return Decision::reject((int) $payload);
            }
            $reservations[] = ['type' => 'throttle', 'key' => $key, 'entry' => $entry];
        }

        if ($limit->concurrency !== null) {
            $setKey = self::KEY_PREFIX . ':c:' . $limit->name . ':' . $bucketKey;
            $slotId = Str::uuid()->toString();
            $slotKey = self::KEY_PREFIX . ':slot:' . $slotId;

            [$ok, $payload] = $conn->eval(
                $this->concurrencyScript(),
                2,
                $setKey,
                $slotKey,
                $limit->concurrency->max,
                $limit->concurrency->slotTtlSeconds,
                $slotId,
            );

            if ((int) $ok !== 1) {
                if ($reservations) {
                    $this->rollback($reservations);
                }
                return Decision::reject((int) $payload);
            }

            $reservations[] = [
                'type' => 'concurrency',
                'setKey' => $setKey,
                'slotKey' => $slotKey,
                'slotId' => $slotId,
            ];
        }

        return Decision::admit($reservations);
    }

    public function release(array $reservations): void
    {
        $conn = $this->redis->connection($this->connectionName);
        foreach ($reservations as $r) {
            if (($r['type'] ?? null) === 'concurrency') {
                $conn->srem($r['setKey'], $r['slotId']);
                $conn->del($r['slotKey']);
            }
            // throttle entries are not actively released — they age out via window
        }
    }

    public function rollback(array $reservations): void
    {
        $conn = $this->redis->connection($this->connectionName);
        foreach ($reservations as $r) {
            if (($r['type'] ?? null) === 'throttle') {
                $conn->zrem($r['key'], $r['entry']);
            } elseif (($r['type'] ?? null) === 'concurrency') {
                $conn->srem($r['setKey'], $r['slotId']);
                $conn->del($r['slotKey']);
            }
        }
    }

    /**
     * Sweep orphaned slots from a concurrency set. Called by sunset:sweep-rate-limit-slots.
     */
    public function reconcileSlots(string $setKey): int
    {
        $conn = $this->redis->connection($this->connectionName);
        $removed = $conn->eval(
            $this->reconcileScript(),
            1,
            $setKey,
            self::KEY_PREFIX . ':slot:',
        );
        return (int) $removed;
    }

    private function throttleScript(): string
    {
        return file_get_contents(__DIR__ . '/Lua/check_throttle.lua');
    }

    private function concurrencyScript(): string
    {
        return file_get_contents(__DIR__ . '/Lua/check_concurrency.lua');
    }

    private function reconcileScript(): string
    {
        return file_get_contents(__DIR__ . '/Lua/reconcile_slots.lua');
    }
}
