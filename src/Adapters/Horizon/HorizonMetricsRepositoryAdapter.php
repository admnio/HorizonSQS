<?php

namespace Admnio\Sunset\Adapters\Horizon;

use Admnio\Sunset\Contracts\MetricsRepository as SunsetMetricsRepo;
use Laravel\Horizon\Contracts\MetricsRepository as HorizonMetricsRepository;

class HorizonMetricsRepositoryAdapter implements HorizonMetricsRepository
{
    /**
     * Buffered last (job, runtime) seen via incrementJob, to be paired with
     * the next incrementQueue call.
     */
    private ?array $pendingJob = null;

    public function __construct(private SunsetMetricsRepo $metrics) {}

    public function measuredJobs()    { return $this->metrics->jobs(); }
    public function measuredQueues()  { return $this->metrics->queues(); }
    public function snapshotsForJob($job)    { return $this->metrics->snapshotsForJob($job); }
    public function snapshotsForQueue($queue) { return $this->metrics->snapshotsForQueue($queue); }
    public function throughputForJob($job)   { return $this->metrics->throughputForJob($job); }
    public function throughputForQueue($queue) { return $this->metrics->throughputForQueue($queue); }
    public function runtimeForJob($job)      { return $this->metrics->runtimeForJob($job); }
    public function runtimeForQueue($queue)  { return $this->metrics->runtimeForQueue($queue); }
    public function snapshot() { $this->metrics->snapshot(); }

    public function jobsProcessedPerMinute()
    {
        // Throughput accumulates per minute (snapshot resets); good enough for
        // the dashboard's display.
        return $this->throughput();
    }

    public function throughput()
    {
        $sum = 0;
        foreach ($this->measuredJobs() as $job) {
            $sum += $this->throughputForJob($job);
        }
        return $sum;
    }

    public function queueWithMaximumThroughput()
    {
        $max = null;
        $best = -1;
        foreach ($this->measuredQueues() as $q) {
            $tp = $this->throughputForQueue($q);
            if ($tp > $best) {
                $best = $tp;
                $max = $q;
            }
        }
        return $max;
    }

    public function queueWithMaximumRuntime()
    {
        $max = null;
        $best = -1.0;
        foreach ($this->measuredQueues() as $q) {
            $rt = $this->runtimeForQueue($q);
            if ($rt > $best) {
                $best = $rt;
                $max = $q;
            }
        }
        return $max;
    }

    public function incrementJob($job, $runtime)
    {
        $this->pendingJob = ['job' => $job, 'runtime' => (float) $runtime];
    }

    public function incrementQueue($queue, $runtime)
    {
        if ($this->pendingJob !== null) {
            $this->metrics->incrementThroughput(
                $this->pendingJob['job'],
                $queue,
                (float) $runtime
            );
            $this->pendingJob = null;
        }
        // If no pending job, this is an orphan increment — silently drop. Should
        // not happen in normal Horizon usage where incrementJob always precedes.
    }

    public function acquireWaitTimeMonitorLock()
    {
        return $this->metrics->acquireWaitTimeLock(60);
    }

    public function forget($key)
    {
        $this->metrics->forgetJob($key);
        $this->metrics->forgetQueue($key);
    }

    public function clear()
    {
        foreach ($this->measuredJobs() as $job) {
            $this->metrics->forgetJob($job);
        }
        foreach ($this->measuredQueues() as $q) {
            $this->metrics->forgetQueue($q);
        }
    }
}
