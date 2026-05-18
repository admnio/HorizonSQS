<?php

namespace Admnio\Sunset\Queue\Delay;

use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Psr\Log\LoggerInterface;
use Throwable;

class DelayedJobReenqueuer
{
    public function __construct(
        private DelayedJobStore $store,
        private QueueFactory $queues,
        private LoggerInterface $logger,
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
            } catch (Throwable $e) {
                $this->logger->warning('horizon-sqs: failed to re-enqueue delayed job', [
                    'queue' => $entry['queue'],
                    'member' => $entry['member'],
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
