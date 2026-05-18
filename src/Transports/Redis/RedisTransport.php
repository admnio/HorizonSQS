<?php

namespace Admnio\Sunset\Transports\Redis;

use Admnio\Sunset\Contracts\Transport;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

class RedisTransport implements Transport
{
    public function __construct(
        private RedisFactory $redis,
        private array $packageConfig,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function name(): string
    {
        return 'redis';
    }

    public function connect(array $config): Queue
    {
        return new RedisQueue(
            $this->redis,
            $config['queue'] ?? 'default',
            $config['connection'] ?? 'default',
            $config['retry_after'] ?? 60,
            $config['block_for'] ?? null,
            $config['after_commit'] ?? null,
            $config['migration_batch_size'] ?? -1,
        );
    }

    public function workload(array $queues): array
    {
        $logger = $this->logger ?? new NullLogger();
        $connectionName = $this->packageConfig['transports']['redis']['workload_connection'] ?? 'default';
        $conn = $this->redis->connection($connectionName);

        $records = [];
        foreach ($queues as $queue) {
            try {
                $length = (int) $conn->llen("queues:{$queue}");
                $length += (int) $conn->zcard("queues:{$queue}:delayed");
                $length += (int) $conn->zcard("queues:{$queue}:reserved");
            } catch (Throwable $e) {
                $logger->warning('sunset: Redis workload query failed for queue', [
                    'queue' => $queue,
                    'error' => $e->getMessage(),
                ]);
                $length = 0;
            }

            $records[] = [
                'name' => $queue,
                'length' => $length,
                'wait' => 0,
                'processes' => 0,
                'split_queues' => null,
            ];
        }

        return $records;
    }
}
