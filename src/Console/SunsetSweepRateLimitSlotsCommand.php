<?php

namespace Admnio\Sunset\Console;

use Admnio\Sunset\RateLimiting\RedisLimiter;
use Illuminate\Console\Command;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Str;

/**
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on the published Admnio\Sunset\Contracts\* interfaces instead.
 */
class SunsetSweepRateLimitSlotsCommand extends Command
{
    protected $signature = 'sunset:sweep-rate-limit-slots';

    protected $description = 'Reconcile orphaned rate-limit concurrency slots against the Redis slot keys.';

    /**
     * Cache the Redis client prefix once per command invocation. Same rationale
     * as RateLimitStatsRepository — reflection-and-method-existence probing
     * doesn't change between calls on the same connection.
     */
    private ?string $cachedPrefix = null;

    public function handle(RedisLimiter $limiter, RedisFactory $redis): int
    {
        $conn = $redis->connection(config('sunset.redis_connection'));
        $prefix = $this->detectPrefix($conn);

        $sets = $conn->keys('sunset:rl:c:*');
        $total = 0;
        foreach ($sets as $key) {
            // Laravel's Redis driver applies its configured prefix to KEYS args
            // passed into eval(). $conn->keys() returns FULLY PREFIXED keys
            // straight from the wire, so if we hand them back to reconcileSlots()
            // (which itself calls eval) the prefix is applied a second time and
            // the Lua script ends up working on a non-existent double-prefixed
            // key. Strip the prefix here so reconcileSlots gets the bare key
            // the rest of the rate-limit code uses.
            $unprefixed = ($prefix !== '' && str_starts_with($key, $prefix))
                ? Str::after($key, $prefix)
                : $key;
            $total += $limiter->reconcileSlots($unprefixed);
        }
        $count = count($sets);
        $this->info("Swept {$total} orphaned slot(s) across {$count} concurrency set(s).");
        return self::SUCCESS;
    }

    /**
     * Detect the Redis key prefix the underlying client is using. phpredis
     * stores it as a runtime option; predis exposes it via the connection
     * options object. Returns '' when no prefix is configured or when the
     * client doesn't surface one we can read.
     */
    private function detectPrefix($conn): string
    {
        if ($this->cachedPrefix !== null) {
            return $this->cachedPrefix;
        }

        // phpredis: PhpRedisConnection wraps a \Redis client that exposes
        // _prefix('') (returns the configured prefix with the given suffix
        // appended) and getOption(\Redis::OPT_PREFIX).
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
                // predis: connection client exposes getOptions()->__get('prefix').
                if (is_object($client) && method_exists($client, 'getOptions')) {
                    $opts = $client->getOptions();
                    if (is_object($opts) && method_exists($opts, '__get')) {
                        $p = $opts->__get('prefix');
                        if (is_string($p) && $p !== '') {
                            return $this->cachedPrefix = $p;
                        }
                    }
                }
            } catch (\Throwable) {
                // fall through to config fallback
            }
        }

        // Fallback: the prefix Laravel hands the client at construction time.
        return $this->cachedPrefix = (string) config('database.redis.options.prefix', '');
    }
}
