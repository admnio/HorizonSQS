<?php

namespace Admnio\Sunset\Transports\Redis;

use Illuminate\Queue\RedisQueue as LaravelRedisQueue;
use Illuminate\Support\Str;
use Laravel\Horizon\Events\JobPending;
use Laravel\Horizon\Events\JobPushed;
use Laravel\Horizon\Events\JobReserved;
use Laravel\Horizon\JobPayload;

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

        $this->raiseHorizonEvent(new JobPending($prepared->value), $queue);

        $result = parent::pushRaw($prepared->value, $queue, $options);

        $this->raiseHorizonEvent(new JobPushed($prepared->value), $queue);

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
            $this->raiseHorizonEvent(new JobReserved($job->getReservedJob()), $queue);
        }

        return $job;
    }

    private function raiseHorizonEvent($event, ?string $queue): void
    {
        $queueName = Str::replaceFirst('queues:', '', $queue ?? $this->default);
        event($event->connection($this->getConnectionName())->queue($queueName));
    }
}
