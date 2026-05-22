<?php

namespace Admnio\Sunset\RateLimiting;

use Admnio\Sunset\Repositories\Redis\Concerns\IteratesRedisKeys;
use Illuminate\Contracts\Redis\Factory as RedisFactory;

/**
 * Read-only view over the `sunset:rl:rejects:*` counters that
 * {@see RateLimitGate::applyReject()} increments on every reject.
 *
 * Counter key shape: `sunset:rl:rejects:<connection>:<queue>:<limit-name>`.
 * The TTL on each counter matches the throttle window so old data ages out
 * naturally — this repository simply lists what's currently live and sorts
 * the most-rejected limits first for dashboard display.
 *
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on the published Admnio\Sunset\Contracts\* interfaces instead.
 */
class RateLimitStatsRepository
{
    use IteratesRedisKeys;

    private const REJECT_PREFIX = 'sunset:rl:rejects:';

    public function __construct(
        private RedisFactory $redis,
        private string $connectionName,
    ) {
    }

    /**
     * Return rejection counts grouped by (connection, queue, limit name).
     *
     * @return array<int, array{
     *   connection: string,
     *   queue: string,
     *   limit: string,
     *   count: int,
     *   ttl_seconds: int,
     * }>
     */
    public function rejectsByLimit(): array
    {
        $conn = $this->redis->connection($this->connectionName);

        $rows = [];
        // scanKeys() yields UNPREFIXED keys so we can pass them straight to
        // get/ttl through Laravel's wrapper without double-prefixing.
        // KEYS is O(n) and blocks Redis for the duration of the scan, which
        // makes it dangerous on production-sized keyspaces. SCAN is
        // cursor-based and runs in small, non-blocking chunks.
        foreach ($this->scanKeys($conn, self::REJECT_PREFIX . '*') as $logicalKey) {
            if (! str_starts_with($logicalKey, self::REJECT_PREFIX)) {
                continue;
            }

            $suffix = substr($logicalKey, strlen(self::REJECT_PREFIX));
            $parts = explode(':', $suffix, 3);
            if (count($parts) !== 3) {
                continue; // malformed key — skip rather than crash the page
            }
            [$connection, $queue, $limit] = $parts;

            $count = (int) $conn->get($logicalKey);
            $ttl = (int) $conn->ttl($logicalKey);

            $rows[] = [
                'connection'  => $connection,
                'queue'       => $queue,
                'limit'       => $limit,
                'count'       => $count,
                'ttl_seconds' => $ttl < 0 ? 0 : $ttl,
            ];
        }

        // Sort by count descending so the most-rejected limits surface first.
        usort($rows, fn ($a, $b) => $b['count'] <=> $a['count']);
        return $rows;
    }
}
