<?php

namespace Admnio\Sunset\Queue;

use Aws\S3\S3Client;
use Aws\Sqs\SqsClient;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Queue\Connectors\ConnectorInterface;
use Admnio\Sunset\Queue\Delay\DelayedJobStore;
use Admnio\Sunset\Queue\Payload\ExtendedPayloadHandler;
use Admnio\Sunset\Support\FifoMessageAttributes;

class HorizonSqsConnector implements ConnectorInterface
{
    public function __construct(
        private Container $container,
        private RedisFactory $redis,
        private array $packageConfig,
    ) {
    }

    public function connect(array $config)
    {
        $sqs = new SqsClient($this->normalizeSqsConfig($config));

        $extended = null;
        if ($this->packageConfig['extended_payload']['enabled'] ?? false) {
            // When the container has an explicit binding (provider registered it),
            // reuse so listener and queue share the same instance.
            if ($this->container->bound(ExtendedPayloadHandler::class)) {
                $extended = $this->container->make(ExtendedPayloadHandler::class);
            } else {
                $s3Config = $this->normalizeSqsConfig($config);
                if (! empty($s3Config['endpoint'])) {
                    $s3Config['use_path_style_endpoint'] = true;
                }
                $s3 = new S3Client($s3Config);
                $extended = new ExtendedPayloadHandler(
                    $s3,
                    $this->packageConfig['extended_payload']['bucket'],
                    $this->packageConfig['extended_payload']['prefix']
                );
            }
        }

        return new HorizonSqsQueue(
            sqs: $sqs,
            default: $config['queue'],
            prefix: $config['prefix'] ?? '',
            suffix: $config['suffix'] ?? '',
            fifoAttributes: new FifoMessageAttributes($this->packageConfig['fifo']),
            extendedPayload: $extended,
            delayedStore: new DelayedJobStore($this->redis, $this->packageConfig['redis_connection']),
            maxNativeDelay: (int) ($this->packageConfig['sqs_max_delay'] ?? 900),
            longPollSeconds: max(0, min(20, (int) ($config['wait_time'] ?? 20))),
        );
    }

    private function normalizeSqsConfig(array $config): array
    {
        $base = [
            'region' => $config['region'] ?? 'us-east-1',
            'version' => 'latest',
        ];

        if (! empty($config['key']) && ! empty($config['secret'])) {
            $base['credentials'] = [
                'key' => $config['key'],
                'secret' => $config['secret'],
                'token' => $config['token'] ?? null,
            ];
        }

        if (! empty($config['endpoint'])) {
            $base['endpoint'] = $config['endpoint'];
        }

        return $base;
    }
}
