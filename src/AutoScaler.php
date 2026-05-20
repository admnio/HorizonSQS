<?php

namespace Admnio\Sunset;

use Admnio\Sunset\Contracts\MetricsRepository;
use Admnio\Sunset\Supervisor\Supervisor;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Support\Collection;

class AutoScaler
{
    /**
     * The queue factory implementation.
     *
     * @var \Illuminate\Contracts\Queue\Factory
     */
    public $queue;

    /**
     * The metrics repository implementation.
     *
     * @var \Admnio\Sunset\Contracts\MetricsRepository
     */
    public $metrics;

    /**
     * Create a new auto-scaler instance.
     *
     * @param  \Illuminate\Contracts\Queue\Factory  $queue
     * @param  \Admnio\Sunset\Contracts\MetricsRepository  $metrics
     * @return void
     */
    public function __construct(QueueFactory $queue, MetricsRepository $metrics)
    {
        $this->queue = $queue;
        $this->metrics = $metrics;
    }

    /**
     * Balance the workers on the given supervisor.
     *
     * @param  \Admnio\Sunset\Supervisor\Supervisor  $supervisor
     * @return void
     */
    public function scale(Supervisor $supervisor)
    {
        $pools = $this->poolsByQueue($supervisor);

        $workers = $this->numberOfWorkersPerQueue(
            $supervisor, $this->timeToClearPerQueue($supervisor, $pools)
        );

        $workers->each(function ($workers, $queue) use ($supervisor, $pools) {
            $this->scalePool($supervisor, $pools[$queue], $workers);
        });
    }

    /**
     * Get the process pools keyed by their queue name.
     *
     * @param  \Admnio\Sunset\Supervisor\Supervisor  $supervisor
     * @return \Illuminate\Support\Collection
     */
    protected function poolsByQueue(Supervisor $supervisor)
    {
        return $supervisor->processPools->mapWithKeys(function ($pool) {
            return [$pool->queue() => $pool];
        });
    }

    /**
     * Get the times in milliseconds needed to clear the queues.
     *
     * @param  \Admnio\Sunset\Supervisor\Supervisor  $supervisor
     * @param  \Illuminate\Support\Collection  $pools
     * @return \Illuminate\Support\Collection
     */
    protected function timeToClearPerQueue(Supervisor $supervisor, Collection $pools)
    {
        return $pools->mapWithKeys(function ($pool, $queue) use ($supervisor) {
            $queues = collect(explode(',', $queue))->map(function ($_queue) use ($supervisor) {
                // Use Laravel's native Queue::size() instead of Horizon's readyNow() macro.
                // size() includes delayed + reserved jobs on Redis, which over-counts for
                // pure autoscaling purposes; in practice the discrepancy only matters when
                // many jobs are sitting in delayed state and the over-count just makes
                // autoscaling a little eager. Acceptable trade-off for v0.8.x; revisit if
                // a precise "ready now" count becomes critical (would require per-transport
                // logic against the underlying queue stores).
                $size = $this->queue->connection($supervisor->options->connection)->size($_queue);

                return [
                    'size' => $size,
                    'time' => ($size * $this->metrics->runtimeForQueue($_queue)),
                ];
            });

            return [$queue => [
                'size' => $queues->sum('size'),
                'time' => $queues->sum('time'),
            ]];
        });
    }

    /**
     * Get the number of workers needed per queue for proper balance.
     *
     * @param  \Admnio\Sunset\Supervisor\Supervisor  $supervisor
     * @param  \Illuminate\Support\Collection  $queues
     * @return \Illuminate\Support\Collection
     */
    protected function numberOfWorkersPerQueue(Supervisor $supervisor, Collection $queues)
    {
        $timeToClearAll = $queues->sum('time');
        $totalJobs = $queues->sum('size');

        return $queues->mapWithKeys(function ($timeToClear, $queue) use ($supervisor, $timeToClearAll, $totalJobs) {
            if (! $supervisor->options->balancing()) {
                $targetProcesses = min(
                    $supervisor->options->maxProcesses,
                    max($supervisor->options->minProcesses, $timeToClear['size'])
                );

                return [$queue => $targetProcesses];
            }

            if ($timeToClearAll > 0 &&
                $supervisor->options->autoScaling()) {
                $numberOfProcesses = $supervisor->options->autoScaleByNumberOfJobs()
                    ? ($timeToClear['size'] / $totalJobs)
                    : ($timeToClear['time'] / $timeToClearAll);

                return [$queue => $numberOfProcesses *= $supervisor->options->maxProcesses];
            } elseif ($timeToClearAll == 0 &&
                      $supervisor->options->autoScaling()) {
                return [
                    $queue => $timeToClear['size']
                        ? $supervisor->options->maxProcesses
                        : $supervisor->options->minProcesses,
                ];
            }

            return [$queue => $supervisor->options->maxProcesses / count($supervisor->processPools)];
        })->sort();
    }

    /**
     * Scale the given pool to the recommended number of workers.
     *
     * @param  \Admnio\Sunset\Supervisor\Supervisor  $supervisor
     * @param  \Admnio\Sunset\Supervisor\ProcessPool  $pool
     * @param  float  $workers
     * @return void
     */
    protected function scalePool(Supervisor $supervisor, $pool, $workers)
    {
        $supervisor->pruneTerminatingProcesses();

        $totalProcessCount = $pool->totalProcessCount();

        $desiredProcessCount = ceil($workers);

        if ($desiredProcessCount > $totalProcessCount) {
            $maxUpShift = min(
                max(0, $supervisor->options->maxProcesses - $supervisor->totalProcessCount()),
                $supervisor->options->balanceMaxShift
            );

            $pool->scale(
                min(
                    $totalProcessCount + $maxUpShift,
                    max($supervisor->options->minProcesses, $supervisor->options->maxProcesses - (($supervisor->processPools->count() - 1) * $supervisor->options->minProcesses)),
                    $desiredProcessCount
                )
            );
        } elseif ($desiredProcessCount < $totalProcessCount) {
            $maxDownShift = min(
                $supervisor->totalProcessCount() - $supervisor->options->minProcesses,
                $supervisor->options->balanceMaxShift
            );

            $pool->scale(
                max(
                    $totalProcessCount - $maxDownShift,
                    $supervisor->options->minProcesses,
                    $desiredProcessCount
                )
            );
        }
    }
}
