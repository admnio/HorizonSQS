<?php

namespace Admnio\Sunset\Repositories\Redis;

use Admnio\Sunset\Contracts\WorkerMetricsRepository;
use Admnio\Sunset\Telemetry\WorkerMetricsSnapshot;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use InvalidArgumentException;
use Throwable;

/**
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on Admnio\Sunset\Contracts\WorkerMetricsRepository for reads. The
 *           write side (record()) is internal to the Looping listener.
 *
 * Storage layout (under the configured sunset: key prefix):
 *   - {prefix}:worker_metrics:{pid}              hash, TTL 30s, snake_case fields
 *   - {prefix}:worker_metrics:{pid}:series:rss   ZSET, score=ts, member="ts:rss",
 *                                                capped to telemetry.series_points,
 *                                                TTL 600s
 *   - {prefix}:worker_metrics:{pid}:series:cpu   ZSET, score=ts, member="ts:cpuInt"
 *                                                where cpuInt = (int) round(cpuPct * 100);
 *                                                only written when cpuPct !== null,
 *                                                same cap + TTL as rss
 *   - {prefix}:worker_metrics:pids               SET of currently-known PIDs;
 *                                                self-cleans on all() when a hash
 *                                                has expired
 */
class RedisWorkerMetricsRepository implements WorkerMetricsRepository
{
    /** Hash TTL — matches the "live worker" window from the spec. */
    private const HASH_TTL_SECONDS = 30;

    /** Series ZSET TTL — keeps sparklines hydrated for ~10 minutes of replay. */
    private const SERIES_TTL_SECONDS = 600;

    public function __construct(private RedisFactory $redis)
    {
    }

    /**
     * Persist a snapshot to Redis: write the hash, append to both series, and
     * register the PID. Exceptions propagate; the Looping listener is the
     * layer that decides telemetry write failures should be silent.
     */
    public function record(WorkerMetricsSnapshot $snapshot): void
    {
        $pid = $snapshot->pid;
        $hashKey = $this->key("worker_metrics:{$pid}");
        $rssKey  = $this->key("worker_metrics:{$pid}:series:rss");
        $cpuKey  = $this->key("worker_metrics:{$pid}:series:cpu");
        $setKey  = $this->key('worker_metrics:pids');
        $cap     = (int) config('sunset.telemetry.series_points', 60);

        $fields = $snapshot->toArray();
        // queues is an array — JSON-encode for hash storage. null becomes "[]".
        $fields['queues'] = json_encode($snapshot->queues ?? []);
        // Redis hashes can't store null — coerce nulls to '' so HGETALL returns ''
        // and fromArray() round-trips them back to null.
        foreach ($fields as $k => $v) {
            if ($v === null) {
                $fields[$k] = '';
            }
        }

        $cpuMember = $snapshot->cpuPct !== null
            ? "{$snapshot->lastReportAt}:" . (int) round($snapshot->cpuPct * 100)
            : null;

        $this->connection()->pipeline(function ($pipe) use (
            $snapshot, $hashKey, $rssKey, $cpuKey, $setKey, $cap, $fields, $cpuMember
        ) {
            $pipe->hmset($hashKey, $fields);
            $pipe->expire($hashKey, self::HASH_TTL_SECONDS);
            $pipe->sadd($setKey, $snapshot->pid);

            $pipe->zadd(
                $rssKey,
                (float) $snapshot->lastReportAt,
                "{$snapshot->lastReportAt}:{$snapshot->rssBytes}"
            );
            $pipe->zremrangebyrank($rssKey, 0, -1 * ($cap + 1));
            $pipe->expire($rssKey, self::SERIES_TTL_SECONDS);

            if ($cpuMember !== null) {
                $pipe->zadd($cpuKey, (float) $snapshot->lastReportAt, $cpuMember);
                $pipe->zremrangebyrank($cpuKey, 0, -1 * ($cap + 1));
                $pipe->expire($cpuKey, self::SERIES_TTL_SECONDS);
            }
        });
    }

    public function all(): array
    {
        try {
            $conn = $this->connection();
            $setKey = $this->key('worker_metrics:pids');
            $pids = (array) ($conn->smembers($setKey) ?: []);

            if (empty($pids)) {
                return [];
            }

            $hashes = $conn->pipeline(function ($pipe) use ($pids) {
                foreach ($pids as $pid) {
                    $pipe->hgetall($this->key("worker_metrics:{$pid}"));
                }
            });

            $snapshots = [];
            $stale = [];
            foreach ($pids as $i => $pid) {
                $hash = $hashes[$i] ?? null;
                if (empty($hash)) {
                    $stale[] = $pid;
                    continue;
                }
                $snapshots[] = $this->hydrate($hash);
            }

            if (! empty($stale)) {
                $conn->srem($setKey, ...$stale);
            }

            return $snapshots;
        } catch (Throwable) {
            return [];
        }
    }

    public function find(int $pid): ?WorkerMetricsSnapshot
    {
        try {
            $hash = $this->connection()->hgetall($this->key("worker_metrics:{$pid}"));

            if (empty($hash)) {
                return null;
            }

            return $this->hydrate($hash);
        } catch (Throwable) {
            return null;
        }
    }

    public function series(int $pid, string $kind, int $maxPoints = 60): array
    {
        if ($kind !== 'rss' && $kind !== 'cpu') {
            throw new InvalidArgumentException(
                "Unknown series kind '{$kind}'. Expected 'rss' or 'cpu'."
            );
        }

        try {
            $key = $this->key("worker_metrics:{$pid}:series:{$kind}");

            // Newest N entries (by rank), then re-sort ascending below.
            $start = -1 * max($maxPoints, 1);
            $raw = (array) $this->connection()->zrange($key, $start, -1, ['withscores' => true]);

            if (empty($raw)) {
                return [];
            }

            // Both phpredis and predis return [member => score] when withscores=true.
            $points = [];
            foreach ($raw as $member => $score) {
                // Member encoding: "{ts}:{value}". Parse the value side; the score
                // is the canonical ts so we use it directly.
                $colon = strpos((string) $member, ':');
                if ($colon === false) {
                    continue;
                }
                $valuePart = substr((string) $member, $colon + 1);

                $value = $kind === 'cpu'
                    ? ((int) $valuePart) / 100.0
                    : (int) $valuePart;

                $points[] = [
                    'ts' => (int) $score,
                    'value' => $value,
                ];
            }

            // ZRANGE returns ascending by score already, but normalise defensively
            // (and to keep behaviour stable across phpredis/predis edge cases).
            usort($points, fn ($a, $b) => $a['ts'] <=> $b['ts']);

            return $points;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param array<string, mixed>|list<mixed> $hash
     */
    private function hydrate(array $hash): WorkerMetricsSnapshot
    {
        // phpredis HGETALL returns an associative array keyed by field name.
        // predis returns the same shape in current versions. Normalise just in
        // case a future driver returns a numerically-indexed list.
        if (! $this->isAssoc($hash)) {
            $hash = $this->pairsToAssoc($hash);
        }

        // Decode the JSON-encoded queues blob back to an array.
        if (array_key_exists('queues', $hash)) {
            $decoded = $hash['queues'] === '' ? null : json_decode((string) $hash['queues'], true);
            $hash['queues'] = is_array($decoded) ? $decoded : null;
        }

        return WorkerMetricsSnapshot::fromArray($hash);
    }

    /**
     * @param array<int|string, mixed> $arr
     */
    private function isAssoc(array $arr): bool
    {
        if ($arr === []) {
            return true;
        }
        return array_keys($arr) !== range(0, count($arr) - 1);
    }

    /**
     * @param list<mixed> $pairs
     * @return array<string, mixed>
     */
    private function pairsToAssoc(array $pairs): array
    {
        $out = [];
        for ($i = 0, $n = count($pairs); $i < $n; $i += 2) {
            $out[(string) $pairs[$i]] = $pairs[$i + 1] ?? null;
        }
        return $out;
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
