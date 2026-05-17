<?php

namespace MasonWorkforce\HorizonSqs\Queue;

use Aws\S3\S3Client;
use Aws\Sqs\SqsClient;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Queue\Connectors\ConnectorInterface;
use MasonWorkforce\HorizonSqs\Queue\Delay\DelayedJobStore;
use MasonWorkforce\HorizonSqs\Queue\Payload\ExtendedPayloadHandler;
use MasonWorkforce\HorizonSqs\Queue\Payload\PayloadEnricher;
use MasonWorkforce\HorizonSqs\Support\FifoMessageAttributes;

class HorizonSqsConnector implements ConnectorInterface
{
    public function __construct(
        private Container $container,
        private PayloadEnricher $enricher,
        private RedisFactory $redis,
        private array $packageConfig,
    ) {
    }

    public function connect(array $config)
    {
        $sqs = new SqsClient($this->normalizeSqsConfig($config));

        $extended = null;
        if ($this->packageConfig['extended_payload']['enabled'] ?? false) {
            $s3Config = $this->normalizeSqsConfig($config);
            // When an explicit endpoint is set (e.g. LocalStack, MinIO),
            // force path-style addressing so requests target
            // `host/bucket` instead of vhost-style `bucket.host`.
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

        return new HorizonSqsQueue(
            sqs: $sqs,
            default: $config['queue'],
            prefix: $config['prefix'] ?? '',
            suffix: $config['suffix'] ?? '',
            enricher: $this->enricher,
            fifoAttributes: new FifoMessageAttributes($this->packageConfig['fifo']),
            extendedPayload: $extended,
            delayedStore: new DelayedJobStore($this->redis, $this->packageConfig['redis_connection']),
            maxNativeDelay: (int) ($this->packageConfig['sqs_max_delay'] ?? 900),
            longPollSeconds: 20,
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
