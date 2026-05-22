<?php

namespace Admnio\Sunset\Repositories\Redis\Concerns;

use Generator;
use Throwable;

/**
 * Cursor-based key iteration that copes with Laravel's two supported Redis
 * clients (phpredis and predis) plus the prefix quirk documented in v0.9.0:
 * phpredis does NOT auto-prepend OPT_PREFIX to the SCAN MATCH pattern the
 * way it does for GET/SET/etc., so we have to detect the prefix ourselves
 * and prepend it before scanning, then strip it back off the returned keys
 * so the caller can pass them straight back to DEL/HGET/TTL without
 * Laravel's wrapper double-prefixing them on the way out.
 *
 * Extracted in v2.4.1 from three near-identical copies in
 * RedisMetricsRepository, RateLimitStatsRepository, and
 * SunsetSweepRateLimitSlotsCommand.
 *
 * @internal This trait is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x.
 */
trait IteratesRedisKeys
{
    /**
     * Cache the Redis client prefix once per instance. detectRedisPrefix()
     * performs reflection-and-method-existence dancing that doesn't change
     * across calls within the same PHP process (the underlying client doesn't
     * swap prefix mid-request), so we memoise on first read. Null means
     * "not yet probed".
     */
    private ?string $cachedRedisPrefix = null;

    /**
     * Iterate all Redis keys matching $pattern under the given connection.
     *
     * Yields UNPREFIXED keys so callers can pass results back to DEL/HGET/TTL
     * via Laravel's wrapper without the prefix being double-applied. The
     * underlying SCAN traverses the entire keyspace incrementally — safe to
     * call on production-sized data, unlike KEYS.
     *
     * The MATCH filter is applied AFTER bucket scanning, so a batch may
     * return zero matches even when more matching keys exist further down
     * the cursor — we keep iterating until the cursor wraps to '0'.
     *
     * @param  mixed   $connection  raw Redis connection (Laravel's
     *                              PhpRedisConnection / PredisConnection wrapper,
     *                              i.e. the object returned by
     *                              RedisFactory::connection()).
     * @param  string  $pattern     logical (unprefixed) MATCH pattern, e.g.
     *                              `'sunset:rl:rejects:*'`.
     * @return Generator<int, string> unprefixed keys, one per yield.
     */
    protected function scanKeys($connection, string $pattern): Generator
    {
        $prefix = $this->detectRedisPrefix($connection);

        // Redis SCAN's MATCH filter is applied server-side against the
        // raw, fully-qualified key names. The phpredis driver does NOT
        // automatically prepend OPT_PREFIX to the MATCH pattern (unlike
        // get/set/etc.), so we must prepend the prefix ourselves.
        $matchPattern = $prefix . $pattern;

        $rawKeys = [];

        // Drop down to the raw phpredis client when available so we can
        // (a) enable Redis::SCAN_RETRY for the duration of this call —
        //     this makes phpredis loop internally over empty SCAN batches
        //     instead of returning FALSE and forcing us to inspect cursor
        //     state through Laravel's lossy [cursor, []] / FALSE shape, and
        // (b) read the cursor back from the by-reference int parameter
        //     directly, the way phpredis intends.
        if (method_exists($connection, 'client') && defined('\\Redis::OPT_SCAN')) {
            $client = $connection->client();
            if (is_object($client) && method_exists($client, 'scan')) {
                $rawKeys = $this->scanWithPhpRedis($client, $matchPattern);
            }
        }

        if ($rawKeys === []) {
            // Either we're not on phpredis, or the phpredis scan returned
            // nothing. Either way, retry through Laravel's wrapper — predis
            // and very old phpredis live here.
            $rawKeys = $this->scanWithLaravelWrapper($connection, $matchPattern);
        }

        foreach ($rawKeys as $rawKey) {
            // Strip prefix so Laravel's wrapper doesn't double-apply it on
            // subsequent DEL/HGET/TTL calls the caller will make with the
            // yielded key.
            yield $prefix !== '' && str_starts_with($rawKey, $prefix)
                ? substr($rawKey, strlen($prefix))
                : $rawKey;
        }
    }

    /**
     * Detect the Redis client's configured key prefix. phpredis stores it as
     * a runtime option; predis exposes it via the connection options object.
     * Returns '' when no prefix is configured or when the client doesn't
     * surface one we can read.
     *
     * Memoised per-instance: the result doesn't change across calls within
     * the same PHP process (the underlying client doesn't swap prefix
     * mid-request).
     */
    protected function detectRedisPrefix($connection): string
    {
        if ($this->cachedRedisPrefix !== null) {
            return $this->cachedRedisPrefix;
        }

        // phpredis: PhpRedisConnection wraps a \Redis client that exposes
        // _prefix('') (returns the configured prefix with the given suffix
        // appended) and getOption(\Redis::OPT_PREFIX).
        if (method_exists($connection, 'client')) {
            try {
                $client = $connection->client();
                if (is_object($client) && method_exists($client, '_prefix')) {
                    $p = $client->_prefix('');
                    if (is_string($p) && $p !== '') {
                        return $this->cachedRedisPrefix = $p;
                    }
                }
                if (is_object($client) && method_exists($client, 'getOption') && defined('\\Redis::OPT_PREFIX')) {
                    $p = $client->getOption(\Redis::OPT_PREFIX);
                    if (is_string($p) && $p !== '') {
                        return $this->cachedRedisPrefix = $p;
                    }
                }
                // predis: connection client exposes getOptions()->__get('prefix').
                if (is_object($client) && method_exists($client, 'getOptions')) {
                    $opts = $client->getOptions();
                    if (is_object($opts) && method_exists($opts, '__get')) {
                        $p = $opts->__get('prefix');
                        if (is_string($p) && $p !== '') {
                            return $this->cachedRedisPrefix = $p;
                        }
                    }
                }
            } catch (Throwable) {
                // fall through to config fallback
            }
        }

        // Fallback: the prefix Laravel hands the client at construction time.
        return $this->cachedRedisPrefix = (string) config('database.redis.options.prefix', '');
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
    private function scanWithLaravelWrapper($connection, string $matchPattern): array
    {
        $cursor = 0;
        $keys = [];
        do {
            $result = $connection->scan($cursor, ['match' => $matchPattern, 'count' => 100]);
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
}
