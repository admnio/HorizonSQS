<?php

namespace Admnio\Sunset\Contracts;

use Admnio\Sunset\JobPayload;
use Illuminate\Support\Collection;
use Throwable;

interface FailedJobRepository
{
    public function failed(Throwable $e, string $connection, string $queue, JobPayload $payload): void;

    public function findFailed(string $id): ?object;

    public function getFailed(?string $afterIndex = null): Collection;

    public function countFailed(): int;

    public function totalFailed(): int;

    public function countRecentlyFailed(): int;

    public function deleteFailed(string $id): int;

    public function trimFailedJobs(): void;
}
