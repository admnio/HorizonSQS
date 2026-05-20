<?php

namespace Admnio\Sunset\Console;

use Admnio\Sunset\RateLimiting\RedisLimiter;
use Illuminate\Console\Command;
use Illuminate\Contracts\Redis\Factory as RedisFactory;

class SunsetSweepRateLimitSlotsCommand extends Command
{
    protected $signature = 'sunset:sweep-rate-limit-slots';

    protected $description = 'Reconcile orphaned rate-limit concurrency slots against the Redis slot keys.';

    public function handle(RedisLimiter $limiter, RedisFactory $redis): int
    {
        $conn = $redis->connection(config('sunset.redis_connection'));
        $sets = $conn->keys('sunset:rl:c:*');
        $total = 0;
        foreach ($sets as $setKey) {
            $total += $limiter->reconcileSlots($setKey);
        }
        $count = count($sets);
        $this->info("Swept {$total} orphaned slot(s) across {$count} concurrency set(s).");
        return self::SUCCESS;
    }
}
