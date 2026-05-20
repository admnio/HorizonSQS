<?php

namespace Admnio\Sunset\Telemetry;

use Closure;

/**
 * Pure, deterministic per-worker CPU/RSS sampler.
 *
 * Owns the throttle (one sample per intervalSeconds) and the CPU delta math.
 * The clock and resource-usage callables are injected so the class can be
 * unit-tested without touching PHP's getrusage()/microtime().
 *
 * The resource-usage closure must return an array shaped:
 *   ['user_sec' => float, 'sys_sec' => float, 'rss_bytes' => int, 'pid' => int]
 *
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. The public API for
 *           reading per-worker telemetry is
 *           Admnio\Sunset\Contracts\WorkerMetricsRepository.
 */
class WorkerMetricsSampler
{
    private ?float $lastWall = null;
    private ?float $lastCpuSeconds = null;
    private int $startedAt = 0;
    private int $jobsProcessed = 0;
    private int $consecutiveZeroCpuSamples = 0;

    /**
     * @param int     $intervalSeconds Minimum seconds between sample() readings.
     * @param Closure $clock           fn(): float — unix wall seconds.
     * @param Closure $resourceUsage   fn(): array{user_sec: float, sys_sec: float, rss_bytes: int, pid: int}
     */
    public function __construct(
        private int $intervalSeconds,
        private Closure $clock,
        private Closure $resourceUsage,
    ) {
    }

    public function recordJob(): void
    {
        $this->jobsProcessed++;
    }

    /**
     * Take a snapshot if enough wall time has elapsed since the last one.
     *
     * Returns null when throttled. The first call always succeeds and produces
     * a snapshot with cpu_pct === null (no previous reading to delta against).
     *
     * @param list<string>|null $queues
     */
    public function sample(?string $supervisor = null, ?string $connection = null, ?array $queues = null): ?WorkerMetricsSnapshot
    {
        $now = ($this->clock)();

        if ($this->lastWall !== null && ($now - $this->lastWall) < $this->intervalSeconds) {
            return null;
        }

        $usage = ($this->resourceUsage)();
        $cpuSeconds = (float) $usage['user_sec'] + (float) $usage['sys_sec'];
        $cpuPct = null;

        if ($this->lastWall !== null && $this->lastCpuSeconds !== null) {
            $cpuDelta = $cpuSeconds - $this->lastCpuSeconds;
            $wallDelta = max($now - $this->lastWall, 0.001);

            if ($cpuDelta <= 0.0) {
                // Zero delta = no CPU progress this window (typical for Windows where
                // getrusage() returns ru_utime=0/ru_stime=0). Negative delta would
                // indicate a clock jump or counter reset — treat both as zero.
                $this->consecutiveZeroCpuSamples++;
                $cpuPct = $this->consecutiveZeroCpuSamples >= 2 ? null : 0.0;
            } else {
                $this->consecutiveZeroCpuSamples = 0;
                $cpuPct = ($cpuDelta / $wallDelta) * 100.0;
            }
        }

        if ($this->startedAt === 0) {
            $this->startedAt = (int) $now;
        }

        $snapshot = new WorkerMetricsSnapshot(
            pid: (int) $usage['pid'],
            supervisor: $supervisor,
            connection: $connection,
            queues: $queues,
            startedAt: $this->startedAt,
            rssBytes: (int) $usage['rss_bytes'],
            cpuPct: $cpuPct,
            jobsProcessed: $this->jobsProcessed,
            lastReportAt: (int) $now,
        );

        $this->lastWall = $now;
        $this->lastCpuSeconds = $cpuSeconds;

        return $snapshot;
    }
}
