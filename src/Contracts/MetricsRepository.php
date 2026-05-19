<?php

namespace Admnio\Sunset\Contracts;

interface MetricsRepository
{
    public function jobs(): array;

    public function queues(): array;

    public function throughputForJob(string $job): int;

    public function throughputForQueue(string $queue): int;

    public function runtimeForJob(string $job): float;

    public function runtimeForQueue(string $queue): float;

    public function snapshotsForJob(string $job): array;

    public function snapshotsForQueue(string $queue): array;

    public function incrementThroughput(string $jobName, string $queue, float $runtime): void;

    public function acquireWaitTimes(): array;

    public function forgetJob(string $job): void;

    public function forgetQueue(string $queue): void;

    public function snapshot(): void;

    public function latestSnapshotAt(): int;

    public function acquireWaitTimeLock(int $ttlSeconds = 60): bool;
}
