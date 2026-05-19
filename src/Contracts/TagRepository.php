<?php

namespace Admnio\Sunset\Contracts;

use Illuminate\Support\Collection;

interface TagRepository
{
    public function jobs(string $tag, ?string $afterIndex = null): Collection;

    public function paginate(string $tag, ?string $afterIndex = null): array;

    public function count(string $tag): int;

    public function addTemporary(int $expiresAt, string $jobId, array $tags): void;

    public function addPermanent(string $jobId, array $tags): void;

    public function forJobs(array $jobIds): array;

    public function monitor(string $tag): void;

    public function stopMonitoring(string $tag): void;

    public function isMonitoring(string $tag): bool;

    public function monitored(): array;

    public function forget(string $tag): void;
}
