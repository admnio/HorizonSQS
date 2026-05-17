<?php

namespace MasonWorkforce\HorizonSqs\Repositories;

use Aws\Sqs\SqsClient;
use Illuminate\Contracts\Cache\Repository as Cache;
use Laravel\Horizon\Contracts\MetricsRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Laravel\Horizon\Contracts\WorkloadRepository;

class SqsWorkloadRepository implements WorkloadRepository
{
    public function __construct(
        private SqsClient $sqs,
        private MetricsRepository $metrics,
        private SupervisorRepository $supervisors,
        private Cache $cache,
        private string $queuePrefix,
        private array $queues,
        private int $cacheTtlSeconds,
    ) {
    }

    public function get(): array
    {
        return $this->cache->remember(
            'horizon-sqs:workload',
            $this->cacheTtlSeconds,
            fn () => $this->fetch()
        );
    }

    private function fetch(): array
    {
        $perQueueProcesses = $this->processesPerQueue();

        $promises = [];
        foreach ($this->queues as $queue) {
            $promises[$queue] = $this->sqs->getQueueAttributesAsync([
                'QueueUrl' => $this->queuePrefix . '/' . $queue,
                'AttributeNames' => ['ApproximateNumberOfMessages', 'ApproximateNumberOfMessagesNotVisible'],
            ]);
        }

        $workload = [];
        foreach ($promises as $queue => $promise) {
            $result = $promise->wait();
            $attrs = $result['Attributes'] ?? [];
            $length = (int) ($attrs['ApproximateNumberOfMessages'] ?? 0);
            $runtime = (float) $this->metrics->runtimeForQueue($queue);
            $procs = max(1, (int) $this->lookupProcessCount($queue, $perQueueProcesses));

            $workload[] = [
                'name' => $queue,
                'length' => $length,
                'wait' => (int) round($length * $runtime / $procs),
                'processes' => $procs,
                'split_queues' => null,
            ];
        }

        return $workload;
    }

    /**
     * Aggregate per-queue process counts across all supervisors.
     *
     * Mirrors {@see \Laravel\Horizon\Repositories\RedisWorkloadRepository::processes()}.
     * Each supervisor record exposes a `processes` map keyed by `connection:queue`
     * (e.g. `sqs:orders`); counts are summed across supervisors per key.
     *
     * @return array<string, int>
     */
    private function processesPerQueue(): array
    {
        return collect($this->supervisors->all())
            ->pluck('processes')
            ->reduce(function ($final, $queues) {
                foreach ((array) $queues as $queue => $processes) {
                    $final[$queue] = isset($final[$queue])
                        ? $final[$queue] + $processes
                        : $processes;
                }

                return $final;
            }, []) ?? [];
    }

    /**
     * Find the process count for a queue, tolerant of `connection:queue` keys.
     */
    private function lookupProcessCount(string $queue, array $processesPerQueue): int
    {
        if (array_key_exists($queue, $processesPerQueue)) {
            return (int) $processesPerQueue[$queue];
        }

        foreach ($processesPerQueue as $key => $count) {
            if (! is_string($key)) {
                continue;
            }
            $colon = strpos($key, ':');
            if ($colon !== false && substr($key, $colon + 1) === $queue) {
                return (int) $count;
            }
        }

        return 0;
    }
}
