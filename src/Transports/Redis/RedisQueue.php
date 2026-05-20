<?php

namespace Admnio\Sunset\Transports\Redis;

use Admnio\Sunset\Events\JobQueueing;
use Admnio\Sunset\Events\JobQueued;
use Admnio\Sunset\Events\JobReserved;
use Admnio\Sunset\JobPayload;
use Admnio\Sunset\RateLimiting\RateLimitGate;
use Illuminate\Queue\RedisQueue as LaravelRedisQueue;
use Illuminate\Support\Str;

/**
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on the published Admnio\Sunset\Contracts\* interfaces instead.
 */
class RedisQueue extends LaravelRedisQueue
{
    private mixed $lastPushed = null;

    public function push($job, $data = '', $queue = null)
    {
        $this->lastPushed = $job;
        return parent::push($job, $data, $queue);
    }

    public function pushRaw($payload, $queue = null, array $options = [])
    {
        $prepared = (new JobPayload($payload))->prepare($this->lastPushed);
        $queueName = Str::replaceFirst('queues:', '', $queue ?? $this->default);
        $connection = $this->getConnectionName();

        event(new JobQueueing($connection, $queueName, $prepared));

        $result = parent::pushRaw($prepared->value, $queue, $options);

        event(new JobQueued($connection, $queueName, $prepared));

        return $prepared->id();
    }

    public function later($delay, $job, $data = '', $queue = null)
    {
        $this->lastPushed = $job;
        return parent::later($delay, $job, $data, $queue);
    }

    public function pop($queue = null, $index = 0)
    {
        $job = parent::pop($queue, $index);

        if ($job !== null) {
            $queueName = Str::replaceFirst('queues:', '', $queue ?? $this->default);
            $payload = new JobPayload($job->getReservedJob());
            event(new JobReserved($this->getConnectionName(), $queueName, $payload));

            // Rate-limit gate. Resolved lazily per-pop; if no Sunset::for()
            // limits are registered, the gate short-circuits inside admit()
            // before touching Redis, so the no-limit path stays O(1).
            $gate = $this->container->make(RateLimitGate::class);
            $decoded = json_decode($job->getReservedJob(), true) ?: [];
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
