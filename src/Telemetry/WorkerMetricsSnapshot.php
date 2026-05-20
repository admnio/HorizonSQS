<?php

namespace Admnio\Sunset\Telemetry;

/**
 * Immutable per-worker CPU/memory snapshot.
 *
 * Public value object. Consumers reading from the WorkerMetricsRepository
 * receive instances of this DTO. The on-the-wire shape (Redis hash, JSON
 * payloads to the dashboard) uses the snake_case keys produced by toArray().
 */
final class WorkerMetricsSnapshot
{
    /**
     * @param int                 $pid           Worker process ID.
     * @param string|null         $supervisor    Owning supervisor name, if known.
     * @param string|null         $connection    Queue connection name, if known.
     * @param list<string>|null   $queues        Queues the worker is consuming.
     * @param int                 $startedAt     Unix seconds when sampling began.
     * @param int                 $rssBytes      Resident set size in bytes.
     * @param float|null          $cpuPct        CPU utilisation over the last sample window
     *                                           (0-100, or null when not yet computable —
     *                                           first sample, or unavailable on Windows).
     * @param int                 $jobsProcessed Counter of jobs handled by this worker.
     * @param int                 $lastReportAt  Unix seconds of this sample.
     */
    public function __construct(
        public readonly int $pid,
        public readonly ?string $supervisor,
        public readonly ?string $connection,
        public readonly ?array $queues,
        public readonly int $startedAt,
        public readonly int $rssBytes,
        public readonly ?float $cpuPct,
        public readonly int $jobsProcessed,
        public readonly int $lastReportAt,
    ) {
    }

    /**
     * @return array{
     *     pid: int,
     *     supervisor: string|null,
     *     connection: string|null,
     *     queues: list<string>|null,
     *     started_at: int,
     *     rss_bytes: int,
     *     cpu_pct: float|null,
     *     jobs_processed: int,
     *     last_report_at: int
     * }
     */
    public function toArray(): array
    {
        return [
            'pid' => $this->pid,
            'supervisor' => $this->supervisor,
            'connection' => $this->connection,
            'queues' => $this->queues,
            'started_at' => $this->startedAt,
            'rss_bytes' => $this->rssBytes,
            'cpu_pct' => $this->cpuPct,
            'jobs_processed' => $this->jobsProcessed,
            'last_report_at' => $this->lastReportAt,
        ];
    }

    /**
     * Hydrate from the snake_case array shape produced by toArray() (or a
     * Redis HGETALL where all values arrive as strings).
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $queues = $data['queues'] ?? null;
        if ($queues !== null && ! is_array($queues)) {
            $queues = null;
        }

        $cpuPct = $data['cpu_pct'] ?? null;
        if ($cpuPct !== null && $cpuPct !== '') {
            $cpuPct = (float) $cpuPct;
        } else {
            $cpuPct = null;
        }

        return new self(
            pid: (int) ($data['pid'] ?? 0),
            supervisor: isset($data['supervisor']) && $data['supervisor'] !== '' ? (string) $data['supervisor'] : null,
            connection: isset($data['connection']) && $data['connection'] !== '' ? (string) $data['connection'] : null,
            queues: $queues,
            startedAt: (int) ($data['started_at'] ?? 0),
            rssBytes: (int) ($data['rss_bytes'] ?? 0),
            cpuPct: $cpuPct,
            jobsProcessed: (int) ($data['jobs_processed'] ?? 0),
            lastReportAt: (int) ($data['last_report_at'] ?? 0),
        );
    }
}
