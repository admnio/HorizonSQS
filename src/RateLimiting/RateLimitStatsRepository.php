<?php

namespace Admnio\Sunset\RateLimiting;

use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Throwable;

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
    private const REJECT_PREFIX = 'sunset:rl:rejects:';

    /**
     * Cache the Redis client prefix once per instance. detectPrefix() performs
     * reflection-and-method-existence dancing that doesn't change across calls
     * within the same PHP process (the underlying client doesn't swap prefix
     * mid-request), so we memoise on first read. Null means "not yet probed".
     */
    private ?string $cachedPrefix = null;

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
        $prefix = $this->detectPrefix($conn);
        $rawKeys = $this->scanRejectKeys($conn, $prefix);

        $rows = [];
        foreach ($rawKeys as $rawKey) {
            // Strip Redis-client prefix if present (matches the
            // SunsetSweepRateLimitSlotsCommand pattern). $conn->keys() returns
            // FULLY PREFIXED keys straight from the wire; subsequent calls via
            // Laravel's wrapper will re-apply the prefix automatically, so
            // we strip here and pass the bare logical key downstream.
            $logicalKey = $prefix !== '' && str_starts_with($rawKey, $prefix)
                ? substr($rawKey, strlen($prefix))
                : $rawKey;

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

    /**
     * Iterate the reject-counter keyspace with SCAN instead of KEYS.
     *
     * KEYS is O(n) and blocks Redis for the duration of the scan, which
     * makes it dangerous on production-sized keyspaces. SCAN is cursor-based
     * and runs in small, non-blocking chunks. We collect every match across
     * all iterations before returning.
     *
     * The MATCH filter is applied AFTER bucket scanning, so a batch may
     * return zero matches even when more matching keys exist further down
     * the cursor — we keep iterating until the cursor wraps to '0'.
     *
     * @return array<int, string> Fully-prefixed keys as returned by the
     *                            underlying client (the caller strips the
     *                            prefix before subsequent reads).
     */
    private function scanRejectKeys($conn, string $prefix): array
    {
        // Redis SCAN's MATCH filter is applied server-side against the
        // raw, fully-qualified key names. The phpredis driver does NOT
        // automatically prepend OPT_PREFIX to the MATCH pattern (unlike
        // get/set/etc.), so we must prepend the prefix ourselves.
        $matchPattern = $prefix . self::REJECT_PREFIX . '*';

        // Drop down to the raw phpredis client when available so we can
        // (a) enable Redis::SCAN_RETRY for the duration of this call —
        //     this makes phpredis loop internally over empty SCAN batches
        //     instead of returning FALSE and forcing us to inspect cursor
        //     state through Laravel's lossy [cursor, []] / FALSE shape, and
        // (b) read the cursor back from the by-reference int parameter
        //     directly, the way phpredis intends.
        if (method_exists($conn, 'client') && defined('\\Redis::OPT_SCAN')) {
            $client = $conn->client();
            if (is_object($client) && method_exists($client, 'scan')) {
                return $this->scanWithPhpRedis($client, $matchPattern);
            }
        }

        // Fallback for non-phpredis clients (e.g. predis): use Laravel's
        // wrapper. Predis' wrapper exposes [cursor, keys[]] cleanly.
        return $this->scanWithLaravelWrapper($conn, $matchPattern);
    }

    /**
     * @param  \Redis  $client
     * @return array<int, string>
     */
    private function scanWithPhpRedis($client, string $matchPattern): array
    {
        $previousScanOption = null;
        try {
            $previousScanOption = $client->getOption(\Redis::OPT_SCAN);
        } catch (Throwable) {
            // ignore — older phpredis or misconfigured client
        }

        try {
            // SCAN_RETRY makes phpredis loop internally over empty batches
            // until it has keys to return or the cursor has wrapped back to 0.
            $client->setOption(\Redis::OPT_SCAN, \Redis::SCAN_RETRY);

            $cursor = null; // phpredis treats null as "start iteration"
            $keys = [];
            // The phpredis idiom: keep calling scan until it returns FALSE,
            // which signals end-of-iteration. Each call returns the keys
            // found in the current batch (possibly empty under NORETRY, but
            // we're in RETRY so it'll only be empty at the very end).
            while (($batch = $client->scan($cursor, $matchPattern, 100)) !== false) {
                foreach ($batch as $k) {
                    $keys[] = (string) $k;
                }
            }
            return $keys;
        } finally {
            if ($previousScanOption !== null) {
                try {
                    $client->setOption(\Redis::OPT_SCAN, $previousScanOption);
                } catch (Throwable) {
                    // ignore
                }
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private function scanWithLaravelWrapper($conn, string $matchPattern): array
    {
        $cursor = 0;
        $keys = [];
        do {
            $result = $conn->scan($cursor, ['match' => $matchPattern, 'count' => 100]);
            if ($result === false) {
                break;
            }
            if (is_array($result) && count($result) === 2 && is_array($result[1])) {
                $cursor = $result[0];
                $batch = (array) $result[1];
            } else {
                $cursor = 0;
                $batch = (array) ($result ?: []);
            }
            foreach ($batch as $k) {
                $keys[] = (string) $k;
            }
        } while ((string) $cursor !== '0');

        return $keys;
    }

    /**
     * Detect the Redis client's prefix so we can strip it before reading.
     * (Same logic as SunsetSweepRateLimitSlotsCommand::detectPrefix.)
     *
     * Memoised per-instance: the result doesn't change across calls within
     * the same PHP process, and the dashboard polls this on every tick.
     */
    private function detectPrefix($conn): string
    {
        if ($this->cachedPrefix !== null) {
            return $this->cachedPrefix;
        }

        if (method_exists($conn, 'client')) {
            try {
                $client = $conn->client();
                if (is_object($client) && method_exists($client, '_prefix')) {
                    $p = $client->_prefix('');
                    if (is_string($p) && $p !== '') {
                        return $this->cachedPrefix = $p;
                    }
                }
                if (is_object($client) && method_exists($client, 'getOption') && defined('\\Redis::OPT_PREFIX')) {
                    $p = $client->getOption(\Redis::OPT_PREFIX);
                    if (is_string($p) && $p !== '') {
                        return $this->cachedPrefix = $p;
                    }
                }
                if (is_object($client) && method_exists($client, 'getOptions')) {
                    $opts = $client->getOptions();
                    if (is_object($opts) && method_exists($opts, '__get')) {
                        $p = $opts->__get('prefix');
                        if (is_string($p) && $p !== '') {
                            return $this->cachedPrefix = $p;
                        }
                    }
                }
            } catch (Throwable) {
                // fall through to config fallback
            }
        }

        return $this->cachedPrefix = (string) config('database.redis.options.prefix', '');
    }
}
