<?php

namespace Admnio\Sunset\Console;

use Admnio\Sunset\RateLimiting\RedisLimiter;
use Admnio\Sunset\Repositories\Redis\Concerns\IteratesRedisKeys;
use Illuminate\Console\Command;
use Illuminate\Contracts\Redis\Factory as RedisFactory;

/**
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on the published Admnio\Sunset\Contracts\* interfaces instead.
 */
class SunsetSweepRateLimitSlotsCommand extends Command
{
    use IteratesRedisKeys;

    protected $signature = 'sunset:sweep-rate-limit-slots';

    protected $description = 'Reconcile orphaned rate-limit concurrency slots against the Redis slot keys.';

    public function handle(RedisLimiter $limiter, RedisFactory $redis): int
    {
        $conn = $redis->connection(config('sunset.redis_connection'));

        // scanKeys() yields UNPREFIXED keys, which is exactly what
        // reconcileSlots() needs — Laravel's wrapper will re-apply the prefix
        // when reconcileSlots() hands the key to eval()'s KEYS array. If we
        // handed back fully-prefixed keys the Lua script would end up working
        // on a non-existent double-prefixed key.
        //
        // Switched from KEYS to SCAN in v2.4.1: KEYS is O(n) and blocks Redis
        // for the duration of the call, which is dangerous on production
        // keyspaces. SCAN is cursor-based and incremental.
        $total = 0;
        $count = 0;
        foreach ($this->scanKeys($conn, 'sunset:rl:c:*') as $unprefixed) {
            $count++;
            $total += $limiter->reconcileSlots($unprefixed);
        }
        $this->info("Swept {$total} orphaned slot(s) across {$count} concurrency set(s).");
        return self::SUCCESS;
    }
}
