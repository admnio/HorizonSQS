<?php

namespace Admnio\Sunset\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Redis\Factory as RedisFactory;

/**
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on the published Admnio\Sunset\Contracts\* interfaces instead.
 */
class SunsetMigrateHorizonKeysCommand extends Command
{
    protected $signature = 'sunset:migrate-horizon-keys
        {--dry-run : List keys that would change without writing}
        {--from=horizon : Source prefix (default: horizon)}
        {--to=sunset : Destination prefix (default: sunset)}
        {--connection= : Redis connection (defaults to sunset.redis_connection)}';

    protected $description = 'Rename Horizon-prefixed Redis keys to Sunset-prefixed equivalents.';

    public function handle(RedisFactory $redis): int
    {
        $connectionName = $this->option('connection') ?: config('sunset.redis_connection', 'default');
        $conn = $redis->connection($connectionName);
        $from = (string) $this->option('from');
        $to = (string) $this->option('to');
        $dryRun = (bool) $this->option('dry-run');

        $renamed = 0;
        $skipped = 0;
        $errored = 0;

        // phpredis stores keys with a database prefix (e.g. "laravel_database_").
        // SCAN must include this prefix in the pattern to match real key names.
        $prefix = $conn->_prefix('');
        $cursor = 0;

        do {
            [$cursor, $keys] = $this->scanChunk($conn, $cursor, $prefix, $from);

            foreach ($keys as $rawKey) {
                // Strip the Redis key prefix to get the logical key name.
                $source = str_replace($prefix, '', $rawKey);
                $target = $this->translate($source, $conn, $from, $to);

                if ($target === null) {
                    continue;
                }

                if ($source === $target) {
                    continue;
                }

                // Skip if target already exists — idempotent.
                if ($conn->exists($target)) {
                    $this->components->warn("Skipping {$source} — {$target} already exists");
                    $skipped++;
                    continue;
                }

                if ($dryRun) {
                    $this->line("DRY RUN: {$source} → {$target}");
                    $renamed++;
                    continue;
                }

                try {
                    // rename() preserves TTL automatically in Redis.
                    $conn->rename($source, $target);
                    $renamed++;
                } catch (\Throwable $e) {
                    $this->components->error("Failed renaming {$source}: {$e->getMessage()}");
                    $errored++;
                }
            }
        } while ($cursor !== 0 && $cursor !== '0');

        $this->components->info("Renamed: {$renamed}, skipped: {$skipped}, errored: {$errored}");
        return self::SUCCESS;
    }

    /**
     * Scan a chunk of keys matching the source prefix pattern.
     * Uses SCAN via rawCommand/executeRaw to work correctly with phpredis-6,
     * which requires the database prefix to be included in the pattern.
     *
     * @return array{0: int|string, 1: string[]}
     */
    private function scanChunk($conn, $cursor, string $prefix, string $from): array
    {
        $pattern = $prefix . $from . ':*';

        // executeRaw bypasses phpredis argument validation and sends the command directly.
        // rawCommand is an alias that also works. Both return [newCursor, [keys]].
        try {
            $result = $conn->executeRaw(['SCAN', $cursor, 'MATCH', $pattern, 'COUNT', 500]);
        } catch (\Throwable $e) {
            // Fallback: some connection types may not support executeRaw.
            return [0, []];
        }

        if (is_array($result) && count($result) === 2) {
            $newCursor = $result[0];
            $keys = (array) ($result[1] ?? []);
            // Normalise cursor: '0' and 0 both mean "done".
            return [(is_numeric($newCursor) && (int) $newCursor === 0) ? 0 : $newCursor, $keys];
        }

        return [0, []];
    }

    /**
     * Map a source key to its target key name.
     * Returns null if the source key does not start with the expected prefix.
     *
     * Special case: Horizon stores per-job hashes at horizon:{id} (no `:job:` infix).
     * Our schema uses sunset:job:{id}. Detect these by checking that:
     *   1. The suffix has no further colons (single segment).
     *   2. The key type is HASH.
     *   3. The hash contains a `payload` field.
     */
    private function translate(string $source, $conn, string $from, string $to): ?string
    {
        if (! str_starts_with($source, $from . ':')) {
            return null;
        }

        $suffix = substr($source, strlen($from) + 1);

        // Detect Horizon per-job hashes: single-segment suffix + HASH type + payload field.
        if (! str_contains($suffix, ':')) {
            // phpredis-6 returns integer type constants (Redis::REDIS_HASH = 5).
            // Cast to int to handle both integer and string representations.
            $type = (int) $conn->type($source);
            if ($type === \Redis::REDIS_HASH) {
                $fields = (array) $conn->hkeys($source);
                if (in_array('payload', $fields, true)) {
                    return "{$to}:job:{$suffix}";
                }
            }
        }

        return "{$to}:{$suffix}";
    }
}
