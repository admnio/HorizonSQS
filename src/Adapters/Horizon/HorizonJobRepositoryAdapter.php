<?php

namespace Admnio\Sunset\Adapters\Horizon;

use Admnio\Sunset\Contracts\FailedJobRepository as SunsetFailedRepo;
use Admnio\Sunset\Contracts\JobRepository as SunsetJobRepo;
use Admnio\Sunset\JobPayload as SunsetJobPayload;
use Illuminate\Support\Collection;
use Laravel\Horizon\Contracts\JobRepository as HorizonJobRepository;
use Laravel\Horizon\JobPayload as HorizonJobPayload;

class HorizonJobRepositoryAdapter implements HorizonJobRepository
{
    public function __construct(
        private SunsetJobRepo $jobs,
        private SunsetFailedRepo $failed,
    ) {
    }

    public function nextJobId()
    {
        return $this->jobs->nextJobId();
    }

    public function totalRecent()
    {
        return $this->jobs->totalRecent();
    }

    public function totalFailed()
    {
        return $this->failed->totalFailed();
    }

    public function pushed($connection, $queue, HorizonJobPayload $payload)
    {
        $this->jobs->pushed($connection, $queue, $this->wrap($payload));
    }

    public function reserved($connection, $queue, HorizonJobPayload $payload)
    {
        $this->jobs->reserved($connection, $queue, $this->wrap($payload));
    }

    public function released($connection, $queue, HorizonJobPayload $payload, $delay = 0)
    {
        $this->jobs->released($connection, $queue, $this->wrap($payload), (int) $delay);
    }

    public function completed(HorizonJobPayload $payload, $failed = false, $silenced = false)
    {
        $this->jobs->completed($this->wrap($payload), (bool) $silenced);
    }

    public function remember($connection, $queue, HorizonJobPayload $payload)
    {
        $this->jobs->remember($connection, $queue, $this->wrap($payload));
    }

    public function migrated($connection, $queue, Collection $payloads)
    {
        $wrapped = $payloads->map(fn (HorizonJobPayload $p) => $this->wrap($p));
        $this->jobs->migrated($connection, $queue, $wrapped);
    }

    public function failed($exception, $connection, $queue, HorizonJobPayload $payload)
    {
        $this->failed->failed($exception, $connection, $queue, $this->wrap($payload));
    }

    public function findFailed($id)
    {
        return $this->failed->findFailed($id);
    }

    public function getFailed($afterIndex = null)
    {
        return $this->failed->getFailed($afterIndex);
    }

    public function countFailed()
    {
        return $this->failed->countFailed();
    }

    public function countRecentlyFailed()
    {
        return $this->failed->countRecentlyFailed();
    }

    public function deleteFailed($id)
    {
        return $this->failed->deleteFailed($id);
    }

    public function trimFailedJobs()
    {
        $this->failed->trimFailedJobs();
    }

    public function getRecent($afterIndex = null)    { return $this->jobs->getRecent($afterIndex); }
    public function getPending($afterIndex = null)   { return $this->jobs->getPending($afterIndex); }
    public function getCompleted($afterIndex = null) { return $this->jobs->getCompleted($afterIndex); }
    public function getSilenced($afterIndex = null)  { return $this->jobs->getSilenced($afterIndex); }
    public function getJobs(array $ids, $indexFrom = 0) { return $this->jobs->getJobs($ids, $indexFrom); }

    public function countRecent()    { return $this->jobs->countRecent(); }
    public function countPending()   { return $this->jobs->countPending(); }
    public function countCompleted() { return $this->jobs->countCompleted(); }
    public function countSilenced()  { return $this->jobs->countSilenced(); }

    public function trimRecentJobs()    { $this->jobs->trimRecentJobs(); }
    public function trimMonitoredJobs() { $this->jobs->trimMonitoredJobs(); }
    public function deleteMonitored(array $ids) { $this->jobs->deleteMonitored($ids); }
    public function storeRetryReference($id, $retryId) { $this->jobs->storeRetryReference($id, $retryId); }

    private function wrap(HorizonJobPayload $h): SunsetJobPayload
    {
        return new SunsetJobPayload($h->value);
    }
}
