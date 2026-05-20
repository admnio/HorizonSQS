<?php

namespace Admnio\Sunset\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Redis\Factory as RedisFactory;

/**
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on the published Admnio\Sunset\Contracts\* interfaces instead.
 */
class SunsetMigrateRedisKeysCommand extends Command
{
    protected $signature = 'sunset:migrate-redis-keys';

    protected $description = 'Rename legacy horizon-sqs:* sidecar keys to sunset:* after upgrade. Idempotent.';

    public function handle(RedisFactory $redis): int
    {
        $conn = $redis->connection(config('sunset.redis_connection', 'default'));

        $pairs = [
            'horizon-sqs:delayed' => 'sunset:delayed',
        ];

        foreach ($pairs as $old => $new) {
            if ($conn->exists($old) === 0) {
                $this->line("{$old}: no migration needed (key absent)");
                continue;
            }

            $oldCount = $conn->zcard($old);

            if ($conn->exists($new) === 1) {
                $newCount = $conn->zcard($new);
                $this->warn("{$old} ({$oldCount} members) → {$new} ({$newCount} members): refusing to overwrite. Inspect manually.");
                continue;
            }

            $conn->rename($old, $new);
            $finalCount = $conn->zcard($new);
            $this->info("{$old} → {$new} (renamed, {$finalCount} members)");
        }

        return self::SUCCESS;
    }
}
