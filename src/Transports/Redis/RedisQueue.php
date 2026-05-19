<?php

namespace Admnio\Sunset\Transports\Redis;

use Admnio\Sunset\Events\JobQueueing;
use Admnio\Sunset\Events\JobQueued;
use Admnio\Sunset\Events\JobReserved;
use Admnio\Sunset\JobPayload;
use Illuminate\Queue\RedisQueue as LaravelRedisQueue;
use Illuminate\Support\Str;

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
        }

        return $job;
    }
}
