<?php

namespace Admnio\Sunset\Transports\Rabbit;

use Admnio\Sunset\Contracts\Transport;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Queue\Events\WorkerStopping;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;
use VladimirYuldashev\LaravelQueueRabbitMQ\Queue\Connection\ConnectionFactory;
use VladimirYuldashev\LaravelQueueRabbitMQ\Queue\QueueConfigFactory;

/**
 * Sunset's RabbitMQ transport.
 *
 * - {@see connect()} builds a {@see RabbitQueue} (Sunset's subclass of the
 *   vendor queue) with an AMQP connection attached. We deliberately don't
 *   delegate to the vendor connector because it constructs the vendor's
 *   base {@see \VladimirYuldashev\LaravelQueueRabbitMQ\Queue\RabbitMQQueue}
 *   rather than our subclass, and we need the Sunset lifecycle events.
 * - {@see workload()} performs a passive declare per queue to read the
 *   message count. It NEVER throws: if AMQP is unreachable it logs a
 *   warning and returns rows with `length: 0`. The dashboard polls workload
 *   frequently and one transport's outage shouldn't break it.
 *
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on the published Admnio\Sunset\Contracts\* interfaces instead.
 */
class RabbitTransport implements Transport
{
    private LoggerInterface $logger;

    public function __construct(
        private Container $container,
        private array $packageConfig,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function name(): string
    {
        return 'rabbitmq';
    }

    public function connect(array $config): Queue
    {
        $connection = ConnectionFactory::make($config);
        $queueConfig = QueueConfigFactory::make($config);

        $queue = new RabbitQueue($queueConfig, $this->packageConfig);
        $queue->setConnection($connection);
        $queue->setContainer($this->container);

        // Mirror the vendor connector's cleanup so worker shutdown closes
        // the AMQP channel. Otherwise a SIGTERM leaks the socket. The
        // vendor connector registers an identical listener via the event
        // dispatcher; we replicate it here because we bypass that connector.
        if ($this->container->bound('events')) {
            $this->container['events']->listen(
                WorkerStopping::class,
                static function () use ($queue): void {
                    $queue->close();
                }
            );
        }

        return $queue;
    }

    public function workload(array $queues): array
    {
        $connectionName = $this->packageConfig['transports']['rabbitmq']['workload_connection']
            ?? 'rabbitmq';
        $config = config("queue.connections.{$connectionName}");

        $rows = [];

        if (! $config || empty($config['hosts'])) {
            foreach ($queues as $queue) {
                $rows[] = $this->emptyRow($queue);
            }
            return $rows;
        }

        try {
            $host = $config['hosts'][0];
            $conn = new AMQPStreamConnection(
                $host['host'],
                $host['port'],
                $host['user'],
                $host['password'],
                $host['vhost'] ?? '/'
            );
            $channel = $conn->channel();

            foreach ($queues as $queueName) {
                try {
                    // passive declare: read message count without mutating
                    // the queue's declared arguments. Returns
                    // [queue, message_count, consumer_count].
                    [, $messageCount, ] = $channel->queue_declare($queueName, true);
                    $rows[] = [
                        'name' => $queueName,
                        'length' => (int) $messageCount,
                        'processes' => 0,
                        'wait' => 0,
                        'split_queues' => null,
                    ];
                } catch (Throwable $e) {
                    $this->logger->warning('sunset: RabbitMQ workload query failed for queue', [
                        'queue' => $queueName,
                        'error' => $e->getMessage(),
                    ]);
                    $rows[] = $this->emptyRow($queueName);
                }
            }

            $channel->close();
            $conn->close();
        } catch (Throwable $e) {
            $this->logger->warning('sunset: RabbitMQ workload connection failed', [
                'connection' => $connectionName,
                'error' => $e->getMessage(),
            ]);
            $rows = [];
            foreach ($queues as $queueName) {
                $rows[] = $this->emptyRow($queueName);
            }
        }

        return $rows;
    }

    /**
     * @return array{name: string, length: int, processes: int, wait: int, split_queues: null}
     */
    private function emptyRow(string $queue): array
    {
        return [
            'name' => $queue,
            'length' => 0,
            'processes' => 0,
            'wait' => 0,
            'split_queues' => null,
        ];
    }
}
