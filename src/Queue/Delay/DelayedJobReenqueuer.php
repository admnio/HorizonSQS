<?php

namespace MasonWorkforce\HorizonSqs\Queue\Delay;

use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Throwable;

class DelayedJobReenqueuer
{
    public function __construct(
        private DelayedJobStore $store,
        private QueueFactory $queues,
        private string $connectionName,
        private int $sweepIntervalSeconds,
    ) {
    }

    public function sweep(?int $now = null): void
    {
        $now = $now ?? time();
        $entries = $this->store->due($now + $this->sweepIntervalSeconds);

        $queue = $this->queues->connection($this->connectionName);

        foreach ($entries as $entry) {
            try {
                $delay = max(0, (int) round($entry['eta'] - $now));
                $queue->pushRaw($entry['payload'], $entry['queue'], ['delay' => $delay]);
                $this->store->remove($entry['member']);
            } catch (Throwable) {
                // leave in set for next sweep
            }
        }
    }
}
