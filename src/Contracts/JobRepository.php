<?php

namespace Admnio\Sunset\Contracts;

use Admnio\Sunset\JobPayload;
use Illuminate\Support\Collection;

interface JobRepository
{
    public function nextJobId(): string;

    public function pushed(string $connection, string $queue, JobPayload $payload): void;

    public function reserved(string $connection, string $queue, JobPayload $payload): void;

    public function released(string $connection, string $queue, JobPayload $payload, int $delay = 0): void;

    public function completed(JobPayload $payload, bool $silenced = false): void;

    public function remember(string $connection, string $queue, JobPayload $payload): void;

    public function migrated(string $connection, string $queue, Collection $payloads): void;

    public function getRecent(?string $afterIndex = null): Collection;

    public function getPending(?string $afterIndex = null): Collection;

    public function getCompleted(?string $afterIndex = null): Collection;

    public function getSilenced(?string $afterIndex = null): Collection;

    public function getJobs(array $ids, int|string $indexFrom = 0): Collection;

    public function countRecent(): int;

    public function countPending(): int;

    public function countCompleted(): int;

    public function countSilenced(): int;

    public function totalRecent(): int;

    public function trimRecentJobs(): void;

    public function trimMonitoredJobs(): void;

    public function deleteMonitored(array $ids): void;

    public function storeRetryReference(string $id, string $retryId): void;
}
