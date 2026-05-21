<?php

namespace Admnio\Sunset\Transports\Rabbit;

use Admnio\Sunset\Events\JobQueued;
use Admnio\Sunset\Events\JobQueueing;
use Admnio\Sunset\Events\JobReserved;
use Admnio\Sunset\JobPayload;
use Admnio\Sunset\QueuePause\QueuePauseGate;
use Admnio\Sunset\RateLimiting\RateLimitGate;
use Admnio\Sunset\Transports\Sqs\Delay\DelayedJobStore;
use VladimirYuldashev\LaravelQueueRabbitMQ\Queue\Jobs\RabbitMQJob;
use VladimirYuldashev\LaravelQueueRabbitMQ\Queue\QueueConfig;
use VladimirYuldashev\LaravelQueueRabbitMQ\Queue\RabbitMQQueue as VendorQueue;

/**
 * Sunset's RabbitMQ queue driver.
 *
 * Mirrors the shape of {@see \Admnio\Sunset\Transports\Redis\RedisQueue}:
 *  - push/pushRaw fire JobQueueing then JobQueued around the parent publish
 *  - pop fires JobReserved when a job is returned
 *  - later() does NOT use the vendor delayed-queue (per-TTL holding queues)
 *    and instead writes to {@see DelayedJobStore}. RabbitMQ has no native
 *    sorted-set delay; we deliberately avoid the delayed-exchange plugin so
 *    Sunset's reaper is the single source of truth for delayed jobs across
 *    all transports.
 *
 * The parent vendor queue only accepts a {@see QueueConfig} object. The
 * AMQP connection itself is attached after construction via
 * {@see VendorQueue::setConnection()} — handled by the RabbitTransport.
 *
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on the published Admnio\Sunset\Contracts\* interfaces instead.
 */
class RabbitQueue extends VendorQueue
{
    private mixed $lastPushed = null;

    /**
     * Sunset-specific package config (from `config/sunset.php`). Kept so
     * future work (DLX scaffolding, rate limits) can read transport options
     * without re-reading the container.
     */
    private array $packageConfig;

    public function __construct(QueueConfig $config, array $packageConfig = [])
    {
        parent::__construct($config);
        $this->packageConfig = $packageConfig;
    }

    public function push($job, $data = '', $queue = null)
    {
        $this->lastPushed = $job;
        return parent::push($job, $data, $queue);
    }

    public function pushRaw($payload, $queue = null, array $options = []): int|string|null
    {
        $prepared = (new JobPayload($payload))->prepare($this->lastPushed);
        $queueName = $this->getQueue($queue);
        $connection = $this->getConnectionName();

        event(new JobQueueing($connection, $queueName, $prepared));

        $result = parent::pushRaw($prepared->value, $queue, $options);

        event(new JobQueued($connection, $queueName, $prepared));

        // Return what the parent returned (vendor returns int|string|null —
        // the AMQP correlation id). Don't substitute the JobPayload id; the
        // worker side identifies jobs via the AMQP message correlation id.
        return $result;
    }

    /**
     * Schedule a job for delayed dispatch.
     *
     * RabbitMQ has no native sorted-set delay primitive, so rather than rely
     * on the vendor's per-TTL holding-queue trick (or the delayed-exchange
     * plugin), we route delayed jobs through Sunset's {@see DelayedJobStore}
     * — the same Redis-backed buffer used by {@see \Admnio\Sunset\Transports\Sqs\SqsQueue::later()}
     * for delays exceeding SQS's native 15-minute cap. The reaper sweeps the
     * store on its tick and republishes due jobs via {@see pushRaw()}.
     *
     * Lifecycle events (JobQueueing / JobQueued) fire at buffer time — not at
     * reap time — so the dashboard shows delayed jobs immediately for the
     * entire delay window. The reaper does NOT refire these events on
     * promotion. This matches SqsQueue::later()'s buffered long-delay path.
     */
    public function later($delay, $job, $data = '', $queue = null): mixed
    {
        $this->lastPushed = $job;

        $queueName = $this->getQueue($queue);
        $payload = $this->createPayload($job, $queueName, $data);

        $prepared = (new JobPayload($payload))->prepare($job);
        $connection = $this->getConnectionName();

        event(new JobQueueing($connection, $queueName, $prepared));

        /** @var DelayedJobStore $store */
        $store = $this->container->make(DelayedJobStore::class);
        $store->buffer(
            $queueName,
            $this->getConnectionName() ?? 'rabbitmq',
            $prepared->value,
            (float) $this->availableAt($delay)
        );

        event(new JobQueued($connection, $queueName, $prepared));

        return $prepared->id();
    }

    public function pop($queue = null)
    {
        // v1.3.0 queue-pause gate. Consulted BEFORE the AMQP basic_get call so
        // a paused queue costs zero broker round-trips per poll. Mirrors the
        // rate-limit gate's lazy-resolve-per-pop pattern; the gate fails open
        // on Redis errors so a pause-storage outage doesn't silently stop the
        // fleet.
        $queueName = $queue ?: $this->default;
        if ($this->container->make(QueuePauseGate::class)
                ->isPaused((string) $this->getConnectionName(), $queueName)) {
            return null;
        }

        $job = parent::pop($queue);

        if ($job instanceof RabbitMQJob) {
            $queueName = $this->getQueue($queue);
            $payload = new JobPayload($job->getRawBody());
            event(new JobReserved($this->getConnectionName(), $queueName, $payload));

            // Rate-limit gate. Resolved lazily per-pop; if no Sunset::for()
            // limits are registered, the gate short-circuits inside admit()
            // before touching Redis, so the no-limit path stays O(1).
            $gate = $this->container->make(RateLimitGate::class);
            $decoded = json_decode($job->getRawBody(), true) ?: [];
            $decoded['connection'] = $this->getConnectionName();
            $tags = is_array($decoded['tags'] ?? null) ? $decoded['tags'] : [];

            $decision = $gate->admit($job, $decoded, $queueName, $tags);
            if (! $decision->admitted) {
                // Gate already invoked release()/fail()/delete() on the job
                // per the limit's overLimit strategy. Returning null signals
                // the worker to loop without dispatching.
                return null;
            }
        }

        return $job;
    }
}
