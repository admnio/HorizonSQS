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

    public function pushRaw($payload, $queue = null, array $options = [])
    {
        $resolvedQueue = $queue ?: $this->default;
        $queueUrl = $this->getQueue($queue);

        if ($this->extendedPayload) {
            $payload = $this->extendedPayload->maybeStore($payload);
        }

        $args = [
            'QueueUrl' => $queueUrl,
            'MessageBody' => $payload,
        ];

        if (isset($options['delay']) && $options['delay'] > 0) {
            $args['DelaySeconds'] = (int) $options['delay'];
        }

        $args = array_merge($args, $this->fifoAttributes->for($resolvedQueue, $payload, $options));

        $response = $this->sqs->sendMessage($args);
        return $response->get('MessageId');
    }

    public function later($delay, $job, $data = '', $queue = null)
    {
        $resolvedQueue = $queue ?: $this->default;
        $payload = $this->createPayload($job, $resolvedQueue, $data);
        $delaySeconds = $this->secondsUntil($delay);

        if ($delaySeconds > $this->maxNativeDelay) {
            $this->delayedStore->buffer(
                $resolvedQueue,
                $payload,
                microtime(true) + $delaySeconds
            );
            $decoded = json_decode($payload, true);
            return $decoded['id'] ?? null;
        }

        return $this->pushRaw($payload, $queue, ['delay' => $delaySeconds]);
    }

    public function pop($queue = null)
    {
        $queueUrl = $this->getQueue($queue);

        $response = $this->sqs->receiveMessage([
            'QueueUrl' => $queueUrl,
            'AttributeNames' => ['ApproximateReceiveCount'],
            'WaitTimeSeconds' => $this->longPollSeconds,
        ]);

        if (! is_array($response['Messages']) || count($response['Messages']) === 0) {
            return null;
        }

        $message = $response['Messages'][0];

        if ($this->extendedPayload) {
            $message['Body'] = $this->extendedPayload->maybeFetch($message['Body']);
        }

        return new \Illuminate\Queue\Jobs\SqsJob(
            $this->container,
            $this->sqs,
            $message,
            $this->connectionName,
            $queueUrl
        );
    }
}
