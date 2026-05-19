<?php

namespace Admnio\Sunset\Transports\Sqs;

use Admnio\Sunset\Events\JobQueueing;
use Admnio\Sunset\Events\JobQueued;
use Admnio\Sunset\Events\JobReserved;
use Admnio\Sunset\JobPayload;
use Admnio\Sunset\Transports\Sqs\Delay\DelayedJobStore;
use Admnio\Sunset\Transports\Sqs\Payload\ExtendedPayloadHandler;
use Admnio\Sunset\Transports\Sqs\FifoMessageAttributes;
use Aws\Sqs\SqsClient;
use Illuminate\Queue\SqsQueue as LaravelSqsQueue;
use Illuminate\Support\Str;

class SqsQueue extends LaravelSqsQueue
{
    /**
     * The last job pushed via push(); used by createPayloadArray() to drive
     * the JobPayload tag/type derivation, mirroring Horizon's RedisQueue.
     *
     * @var object|string|null
     */
    protected $lastPushed;

    public function __construct(
        SqsClient $sqs,
        string $default,
        string $prefix,
        string $suffix,
        private FifoMessageAttributes $fifoAttributes,
        private ?ExtendedPayloadHandler $extendedPayload,
        private DelayedJobStore $delayedStore,
        private int $maxNativeDelay = 900,
        private int $longPollSeconds = 20,
    ) {
        parent::__construct($sqs, $default, $prefix, $suffix);
    }

    /**
     * Public override so consumers/tests can directly access the prepared payload.
     */
    public function createPayload($job, $queue, $data = '', $delay = null)
    {
        return parent::createPayload($job, $queue, $data, $delay);
    }

    /**
     * Push a new job onto the queue. Records the job so createPayloadArray()
     * (and ultimately JobPayload::prepare) can derive tags and type.
     */
    public function push($job, $data = '', $queue = null)
    {
        $this->lastPushed = $job;

        return parent::push($job, $data, $queue);
    }

    /**
     * Mirror Horizon's RedisQueue::createPayloadArray: ensure `id` is set so the
     * dashboard's recent_jobs/pending_jobs ZSETs key off it.
     */
    protected function createPayloadArray($job, $queue, $data = '')
    {
        $payload = parent::createPayloadArray($job, $queue, $data);

        $payload['id'] = $payload['uuid'];

        return $payload;
    }

    public function pushRaw($payload, $queue = null, array $options = [])
    {
        $resolvedQueue = $queue ?: $this->default;

        // Wrap and prepare the payload Horizon-style so id/type/tags/pushedAt
        // are present before any dispatcher sees the JSON.
        $jobPayload = (new JobPayload($payload))->prepare($this->lastPushed);
        $preparedJson = $jobPayload->value;

        $connection = $this->getConnectionName() ?? '';
        $strippedQueue = $this->stripQueuesPrefix($resolvedQueue);

        event(new JobQueueing($connection, $strippedQueue, $jobPayload));

        // Spill to S3 only AFTER prepare so the JSON we store contains the
        // canonical fields; the SQS body will be the S3 pointer.
        $bodyForSqs = $preparedJson;
        if ($this->extendedPayload) {
            $bodyForSqs = $this->extendedPayload->maybeStore($preparedJson);
        }

        // Laravel passes 'delay' (seconds); SQS expects 'DelaySeconds'.
        if (isset($options['delay']) && $options['delay'] > 0) {
            $options['DelaySeconds'] = (int) $options['delay'];
        }
        unset($options['delay']);

        $args = [
            'QueueUrl' => $this->getQueue($queue),
            'MessageBody' => $bodyForSqs,
        ] + $this->fifoAttributes->for($resolvedQueue, $bodyForSqs, $options) + $options;

        $messageId = $this->sqs->sendMessage($args)->get('MessageId');

        event(new JobQueued($connection, $strippedQueue, $jobPayload));

        return $messageId;
    }

    public function later($delay, $job, $data = '', $queue = null)
    {
        $resolvedQueue = $queue ?: $this->default;
        $this->lastPushed = $job;

        return $this->enqueueUsing(
            $job,
            $this->createPayload($job, $resolvedQueue, $data),
            $queue,
            $delay,
            function ($payload, $queue, $delay) use ($resolvedQueue, $job) {
                $delaySeconds = $this->secondsUntil($delay);

                if ($delaySeconds > $this->maxNativeDelay) {
                    // Buffered long-delay path. Fire JobQueueing/JobQueued now so
                    // the dashboard shows the job immediately; sweep promotion
                    // will NOT refire events.
                    $jobPayload = (new JobPayload($payload))->prepare($job);
                    $preparedJson = $jobPayload->value;
                    $connection = $this->getConnectionName() ?? '';
                    $strippedQueue = $this->stripQueuesPrefix($resolvedQueue);

                    event(new JobQueueing($connection, $strippedQueue, $jobPayload));

                    $this->delayedStore->buffer(
                        $resolvedQueue,
                        $preparedJson,
                        microtime(true) + $delaySeconds
                    );

                    event(new JobQueued($connection, $strippedQueue, $jobPayload));

                    return $jobPayload->id();
                }

                return $this->pushRaw($payload, $queue, ['delay' => $delaySeconds]);
            }
        );
    }

    public function pop($queue = null)
    {
        $queueUrl = $this->getQueue($queue);
        $resolvedQueue = $queue ?: $this->default;

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
            $originalBody = $message['Body'];
            $message['Body'] = $this->extendedPayload->maybeFetch($originalBody);
            // Preserve the original (possibly S3-pointer) body so listeners
            // like CleanupExtendedPayload can still see the pointer after the
            // job processes, when getRawBody() returns the expanded body.
            if ($originalBody !== $message['Body']) {
                $message['SunsetSqsOriginalBody'] = $originalBody;
            }
        }

        // Fire JobReserved with the prepared payload JSON the worker will see.
        $jobPayload = new JobPayload($message['Body']);
        event(new JobReserved($this->getConnectionName() ?? '', $this->stripQueuesPrefix($resolvedQueue), $jobPayload));

        return new \Illuminate\Queue\Jobs\SqsJob(
            $this->container,
            $this->sqs,
            $message,
            $this->connectionName,
            $queueUrl
        );
    }

    /**
     * Strip the "queues:" prefix from a queue name, mirroring Horizon's convention.
     */
    protected function stripQueuesPrefix(string $queue): string
    {
        return Str::replaceFirst('queues:', '', $queue);
    }
}
