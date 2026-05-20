<?php

namespace Admnio\Sunset\Repositories\Redis;

use Admnio\Sunset\Contracts\SupervisorCommandQueue;
use Illuminate\Contracts\Redis\Factory as RedisFactory;

/**
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on the published Admnio\Sunset\Contracts\* interfaces instead.
 */
class RedisSupervisorCommandQueue implements SupervisorCommandQueue
{
    public function __construct(private RedisFactory $redis) {}

    /**
     * Push a command onto a given supervisor's command queue.
     *
     * Commands are JSON-encoded objects: {command: ClassName, options: [...]}
     * pushed via rpush to the tail of the list.
     */
    public function push(string $name, string $command, array $options = []): void
    {
        $this->connection()->rpush(
            $this->key("commands:{$name}"),
            json_encode([
                'command' => $command,
                'options' => $options,
            ])
        );
    }

    /**
     * Get the pending commands for a given supervisor name and clear the queue.
     *
     * Returns an array of decoded command objects, each with `command` and `options`.
     */
    public function pending(string $name): array
    {
        $length = $this->connection()->llen($this->key("commands:{$name}"));

        if ($length < 1) {
            return [];
        }

        $results = $this->connection()->pipeline(function ($pipe) use ($name, $length) {
            $pipe->lrange($this->key("commands:{$name}"), 0, $length - 1);
            $pipe->ltrim($this->key("commands:{$name}"), $length, -1);
        });

        return collect($results[0])
            ->map(fn ($result) => (object) json_decode($result, true))
            ->all();
    }

    /**
     * Flush (delete) the command queue for a given supervisor name.
     */
    public function flush(string $name): void
    {
        $this->connection()->del($this->key("commands:{$name}"));
    }

    private function key(string $name): string
    {
        return config('sunset.key_prefix', 'sunset') . ':' . $name;
    }

    private function connection()
    {
        return $this->redis->connection(config('sunset.redis_connection', 'default'));
    }
}
