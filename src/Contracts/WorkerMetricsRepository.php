<?php

namespace Admnio\Sunset\Contracts;

use Admnio\Sunset\Telemetry\WorkerMetricsSnapshot;

/**
 * Read API for per-worker CPU/memory telemetry.
 *
 * Implementations expose the latest snapshot per worker process plus a
 * bounded sparkline series for each tracked metric. The write side is an
 * implementation detail and is not part of this contract.
 */
interface WorkerMetricsRepository
{
    /**
     * Latest snapshot for every currently-known worker.
     *
     * Implementations should reconcile stale entries (e.g. PIDs whose
     * underlying snapshot has expired) and return only live workers.
     *
     * @return list<WorkerMetricsSnapshot>
     */
    public function all(): array;

    /**
     * Return the snapshot for $pid, or null if no live entry exists.
     */
    public function find(int $pid): ?WorkerMetricsSnapshot;

    /**
     * Sparkline series for the given $pid and metric.
     *
     * Points are returned in ascending timestamp order, oldest first, and
     * capped to $maxPoints entries.
     *
     * @param int    $pid       Worker process ID.
     * @param string $kind      Metric kind. One of 'rss' or 'cpu'.
     * @param int    $maxPoints Maximum number of points to return.
     *
     * @return list<array{ts: int, value: int|float}>
     */
    public function series(int $pid, string $kind, int $maxPoints = 60): array;
}
