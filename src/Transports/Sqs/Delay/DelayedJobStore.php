<?php

namespace Admnio\Sunset\Transports\Sqs\Delay;

use Illuminate\Contracts\Redis\Factory as RedisFactory;

/**
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on the published Admnio\Sunset\Contracts\* interfaces instead.
 */
class DelayedJobStore
{
    private const KEY = 'sunset:delayed';

    public function __construct(
        private RedisFactory $redis,
        private string $connectionName
    ) {
    }

    /**
     * Buffer a job for later dispatch.
     *
     * The ZSET member is encoded as `queue|connection|nonce|payload` so that
     * the reaper can route each due job back to the transport it originated
     * on (SQS, RabbitMQ, Redis, ...). The nonce guarantees member uniqueness
     * even when the same payload is buffered twice on the same queue.
     */
    public function buffer(string $queue, string $connection, string $payload, float $eta): void
    {
        $member = $queue . '|' . $connection . '|' . bin2hex(random_bytes(6)) . '|' . $payload;
        $this->connection()->zadd(self::KEY, (int) $eta, $member);
    }

    public function due(int $now): array
    {
        $raw = $this->connection()->zrangebyscore(self::KEY, '-inf', $now, ['withscores' => true]);

        $entries = [];
        foreach ($raw as $member => $score) {
            // v0.6.0+ members use the 4-segment format `queue|connection|nonce|payload`.
            // Pre-v0.6.0 members (SQS-only era) used the 3-segment format
            // `queue|nonce|payload`; when we see those during the upgrade window
            // we default `connection = 'sqs'` since that's the only transport
            // that wrote to this store before v0.6.0. This fallback can be
            // removed in v0.8.0+ once all old entries have been swept.
            $parts = explode('|', $member, 4);

            if (count($parts) === 4) {
                [$queue, $connection, , $payload] = $parts;
            } else {
                // Legacy 3-segment entry: `queue|nonce|payload`.
                [$queue, , $payload] = explode('|', $member, 3);
                $connection = 'sqs';
            }

            $entries[] = [
                'member' => $member,
                'queue' => $queue,
                'connection' => $connection,
                'payload' => $payload,
                'eta' => (float) $score,
            ];
        }
        return $entries;
    }

    public function remove(string $member): void
    {
        $this->connection()->zrem(self::KEY, $member);
    }

    private function connection()
    {
        return $this->redis->connection($this->connectionName);
    }
}
