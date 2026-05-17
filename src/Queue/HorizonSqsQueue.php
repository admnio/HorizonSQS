<?php

namespace MasonWorkforce\HorizonSqs\Queue;

use Aws\Sqs\SqsClient;
use Illuminate\Queue\SqsQueue;
use MasonWorkforce\HorizonSqs\Queue\Delay\DelayedJobStore;
use MasonWorkforce\HorizonSqs\Queue\Payload\ExtendedPayloadHandler;
use MasonWorkforce\HorizonSqs\Queue\Payload\PayloadEnricher;
use MasonWorkforce\HorizonSqs\Support\FifoMessageAttributes;

class HorizonSqsQueue extends SqsQueue
{
    public function __construct(
        SqsClient $sqs,
        string $default,
        string $prefix,
        string $suffix,
        private PayloadEnricher $enricher,
        private FifoMessageAttributes $fifoAttributes,
        private ?ExtendedPayloadHandler $extendedPayload,
        private DelayedJobStore $delayedStore,
        private int $maxNativeDelay = 900,
        private int $longPollSeconds = 20,
    ) {
        parent::__construct($sqs, $default, $prefix, $suffix);
    }

    public function createPayload($job, $queue, $data = '', $delay = null)
    {
        return parent::createPayload($job, $queue, $data, $delay);
    }

    protected function createPayloadArray($job, $queue, $data = '')
    {
        $payload = parent::createPayloadArray($job, $queue, $data);
        return $this->enricher->enrich($payload, $queue);
    }
}
