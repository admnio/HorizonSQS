<?php

namespace Admnio\Sunset\Transports\Sqs;

use Admnio\Sunset\Contracts\Transport;
use Admnio\Sunset\Transports\Sqs\Delay\DelayedJobStore;
use Admnio\Sunset\Transports\Sqs\Payload\ExtendedPayloadHandler;
use Aws\S3\S3Client;
use Aws\Sqs\SqsClient;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on the published Admnio\Sunset\Contracts\* interfaces instead.
 */
class SqsTransport implements Transport
{
    public function __construct(
        private Container $container,
        private RedisFactory $redis,
        private array $packageConfig,
        private string $queuePrefix = '',
        private ?SqsClient $sqsClient = null,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function name(): string
    {
        return 'sqs';
    }

    public function connect(array $config): Queue
    {
        $sqs = new SqsClient($this->normalizeAwsConfig($config));

        $sqsConfig = $this->packageConfig['transports']['sqs'] ?? [];

        $extended = null;
        if ($sqsConfig['extended_payload']['enabled'] ?? false) {
            // Prefer the container-bound singleton so the queue + JobProcessed
            // listener share the same handler instance.
            if ($this->container->bound(ExtendedPayloadHandler::class)) {
                $extended = $this->container->make(ExtendedPayloadHandler::class);
            } else {
                $s3Config = $this->normalizeAwsConfig($config);
                if (! empty($s3Config['endpoint'])) {
                    $s3Config['use_path_style_endpoint'] = true;
                }
                $extended = new ExtendedPayloadHandler(
                    new S3Client($s3Config),
                    $sqsConfig['extended_payload']['bucket'],
                    $sqsConfig['extended_payload']['prefix']
                );
            }
        }

        return new SqsQueue(
            sqs: $sqs,
            default: $config['queue'],
            prefix: $config['prefix'] ?? '',
            suffix: $config['suffix'] ?? '',
            fifoAttributes: new FifoMessageAttributes($sqsConfig['fifo'] ?? []),
            extendedPayload: $extended,
            delayedStore: new DelayedJobStore($this->redis, $this->packageConfig['redis_connection']),
            maxNativeDelay: (int) ($sqsConfig['sqs_max_delay'] ?? 900),
            longPollSeconds: max(0, min(20, (int) ($config['wait_time'] ?? 20))),
        );
    }

    public function workload(array $queues): array
    {
        $sqs = $this->resolveSqsClient();
        $logger = $this->logger ?? new NullLogger();

        $promises = [];
        foreach ($queues as $queue) {
            $promises[$queue] = $sqs->getQueueAttributesAsync([
                'QueueUrl' => rtrim($this->queuePrefix, '/') . '/' . $queue,
                'AttributeNames' => ['ApproximateNumberOfMessages', 'ApproximateNumberOfMessagesNotVisible'],
            ]);
        }

        $records = [];
        foreach ($promises as $queue => $promise) {
            try {
                $result = $promise->wait();
                $attrs = $result['Attributes'] ?? [];
                $length = (int) ($attrs['ApproximateNumberOfMessages'] ?? 0);
            } catch (Throwable $e) {
                $logger->warning('sunset: GetQueueAttributes failed for queue', [
                    'queue' => $queue,
                    'error' => $e->getMessage(),
                ]);
                $length = 0;
            }

            $records[] = [
                'name' => $queue,
                'length' => $length,
                // wait + processes filled in by the workload repository (transport-agnostic math)
                'wait' => 0,
                'processes' => 0,
                'split_queues' => null,
            ];
        }

        return $records;
    }

    private function resolveSqsClient(): SqsClient
    {
        if ($this->sqsClient) {
            return $this->sqsClient;
        }

        $queueConfig = $this->container->make('config')->get('queue.connections.sqs', []);
        return new SqsClient($this->normalizeAwsConfig($queueConfig));
    }

    private function normalizeAwsConfig(array $config): array
    {
        $base = [
            'region' => $config['region'] ?? 'us-east-1',
            'version' => 'latest',
        ];

        if (! empty($config['key']) && ! empty($config['secret'])) {
            $base['credentials'] = [
                'key' => $config['key'],
                'secret' => $config['secret'],
            ];
            if (! empty($config['token'])) {
                $base['credentials']['token'] = $config['token'];
            }
        }

        if (! empty($config['endpoint'])) {
            $base['endpoint'] = $config['endpoint'];
        }

        return $base;
    }
}
