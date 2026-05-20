<?php

namespace Admnio\Sunset\Transports\Sqs\Delay;

use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Psr\Log\LoggerInterface;
use Throwable;

class DelayedJobReenqueuer
{
    public function __construct(
        private DelayedJobStore $store,
        private QueueFactory $queues,
        private LoggerInterface $logger,
        private int $sweepIntervalSeconds,
    ) {
    }

    public function sweep(?int $now = null): void
    {
        $now = $now ?? time();
        $entries = $this->store->due($now + $this->sweepIntervalSeconds);

        foreach ($entries as $entry) {
            try {
                $delay = max(0, (int) round($entry['eta'] - $now));
                // Route each entry back to the transport it originated on
                // (recorded by DelayedJobStore::buffer). Pre-v0.6.0 legacy
                // entries default to 'sqs' (see DelayedJobStore::due).
                $queue = $this->queues->connection($entry['connection']);
                $queue->pushRaw($entry['payload'], $entry['queue'], ['delay' => $delay]);
                $this->store->remove($entry['member']);
            } catch (Throwable $e) {
                $this->logger->warning('sunset: failed to re-enqueue delayed job', [
                    'queue' => $entry['queue'],
                    'connection' => $entry['connection'] ?? null,
                    'member' => $entry['member'],
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
