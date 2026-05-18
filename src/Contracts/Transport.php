<?php

namespace Admnio\Sunset\Contracts;

use Illuminate\Contracts\Queue\Queue;

interface Transport
{
    /**
     * Stable transport identifier (e.g. 'sqs', 'redis').
     * Used as the key under config('sunset.transports').
     */
    public function name(): string;

    /**
     * Build a Laravel Queue for a given connection config (from config/queue.php).
     *
     * @param array<string, mixed> $config
     */
    public function connect(array $config): Queue;

    /**
     * Per-queue depth metadata for the dashboard's Workload page.
     *
     * @param list<string> $queues
     * @return list<array{name: string, length: int, processes: int, wait: int, split_queues: null|array}>
     */
    public function workload(array $queues): array;
}
