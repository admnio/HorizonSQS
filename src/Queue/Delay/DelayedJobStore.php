<?php

namespace MasonWorkforce\HorizonSqs\Queue\Delay;

use Illuminate\Contracts\Redis\Factory as RedisFactory;

class DelayedJobStore
{
    private const KEY = 'horizon-sqs:delayed';

    public function __construct(
        private RedisFactory $redis,
        private string $connectionName
    ) {
    }

    public function buffer(string $queue, string $payload, float $eta): void
    {
        $member = $queue . '|' . bin2hex(random_bytes(6)) . '|' . $payload;
        $this->connection()->zadd(self::KEY, (int) $eta, $member);
    }

    public function due(int $now): array
    {
        $raw = $this->connection()->zrangebyscore(self::KEY, '-inf', $now, ['withscores' => true]);

        $entries = [];
        foreach ($raw as $member => $score) {
            [$queue, , $payload] = explode('|', $member, 3);
            $entries[] = [
                'member' => $member,
                'queue' => $queue,
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
