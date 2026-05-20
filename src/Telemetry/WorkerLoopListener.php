<?php

namespace Admnio\Sunset\Telemetry;

use Admnio\Sunset\Contracts\WorkerMetricsRepository;
use Closure;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\Looping;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. The public API for
 *           reading per-worker telemetry is
 *           Admnio\Sunset\Contracts\WorkerMetricsRepository.
 *
 * Subscribed to two Laravel queue events:
 *   - Illuminate\Queue\Events\Looping     → samples + writes a snapshot
 *   - Illuminate\Queue\Events\JobProcessed → increments the jobs counter only
 *
 * The listener is bound as a container singleton. Inside the worker process
 * that means a single sampler instance accumulates state (lastWall,
 * lastCpuSeconds, jobsProcessed, startedAt) for the lifetime of the worker —
 * which is exactly what the CPU-delta math requires.
 *
 * Telemetry failures (Redis down, etc.) are silently swallowed; this listener
 * is observability, not load-bearing. Errors surface as debug-level log lines
 * for operators willing to grep for them.
 */
class WorkerLoopListener
{
    private ?WorkerMetricsSampler $sampler = null;

    public function __construct(
        private WorkerMetricsRepository $repository,
        private LoggerInterface $logger,
        private bool $enabled,
        private int $intervalSeconds,
        ?WorkerMetricsSampler $samplerOverride = null,
    ) {
        // Tests inject a mock sampler via the constructor. Production code
        // lets sampler() build one lazily on first event.
        if ($samplerOverride !== null) {
            $this->sampler = $samplerOverride;
        }
    }

    public function handleLooping(Looping $event): void
    {
        if (! $this->enabled) {
            return;
        }

        $snapshot = $this->sampler()->sample(
            supervisor: $this->resolveSupervisor(),
            connection: $event->connectionName,
            queues: $this->resolveQueues($event->queue ?? null),
        );

        if ($snapshot === null) {
            return;
        }

        try {
            $this->repository->record($snapshot);
        } catch (Throwable $e) {
            // Telemetry is observability, not load-bearing. Swallow + debug-log.
            $this->logger->debug(
                'Sunset: WorkerLoopListener failed to record metrics snapshot.',
                ['exception' => $e]
            );
        }
    }

    public function handleJobProcessed(JobProcessed $event): void
    {
        if (! $this->enabled) {
            return;
        }

        $this->sampler()->recordJob();
    }

    /**
     * Build the per-process sampler on first use. The listener is a container
     * singleton, so this method runs once per worker process.
     */
    private function sampler(): WorkerMetricsSampler
    {
        if ($this->sampler !== null) {
            return $this->sampler;
        }

        return $this->sampler = new WorkerMetricsSampler(
            intervalSeconds: $this->intervalSeconds,
            clock: $this->defaultClock(),
            resourceUsage: $this->defaultResourceUsage(),
        );
    }

    private function defaultClock(): Closure
    {
        return static fn (): float => microtime(true);
    }

    private function defaultResourceUsage(): Closure
    {
        return static function (): array {
            $usage = function_exists('getrusage') ? getrusage() : [];
            $userSec = isset($usage['ru_utime.tv_sec'])
                ? ((float) $usage['ru_utime.tv_sec'])
                    + ((float) ($usage['ru_utime.tv_usec'] ?? 0) / 1_000_000.0)
                : 0.0;
            $sysSec = isset($usage['ru_stime.tv_sec'])
                ? ((float) $usage['ru_stime.tv_sec'])
                    + ((float) ($usage['ru_stime.tv_usec'] ?? 0) / 1_000_000.0)
                : 0.0;

            return [
                'user_sec' => $userSec,
                'sys_sec' => $sysSec,
                'rss_bytes' => (int) memory_get_usage(true),
                'pid' => (int) getmypid(),
            ];
        };
    }

    /**
     * Supervisor name. Sunset's WorkerCommandString passes `--supervisor=<name>`
     * to every spawned worker, so we parse argv first. SUNSET_SUPERVISOR env
     * is honored as an override path (eg. for custom spawners). Absent in
     * standalone `artisan queue:work` — null is fine, the dashboard renders
     * "—" cells in that case.
     */
    private function resolveSupervisor(): ?string
    {
        $fromArgv = $this->parseArgvOption('--supervisor=');
        if ($fromArgv !== null) {
            return $fromArgv;
        }

        $raw = $_SERVER['SUNSET_SUPERVISOR'] ?? getenv('SUNSET_SUPERVISOR');
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        return $raw;
    }

    /**
     * Queue list for this worker. The Looping event carries the comma-separated
     * queue string the worker is actually consuming; argv `--queue=` is a
     * fallback for events that arrive without that field populated.
     *
     * @return list<string>|null
     */
    private function resolveQueues(?string $eventQueue): ?array
    {
        $value = is_string($eventQueue) && $eventQueue !== ''
            ? $eventQueue
            : $this->parseArgvOption('--queue=');

        if ($value === null) {
            return null;
        }

        $queues = array_values(array_filter(
            array_map('trim', explode(',', $value)),
            static fn (string $q) => $q !== ''
        ));

        return $queues === [] ? null : $queues;
    }

    /**
     * Best-effort scan of $_SERVER['argv'] for `--key=value` style options.
     * Returns null when not present or argv is unreadable.
     */
    private function parseArgvOption(string $prefix): ?string
    {
        $argv = $_SERVER['argv'] ?? null;
        if (! is_array($argv)) {
            return null;
        }

        foreach ($argv as $arg) {
            if (! is_string($arg) || ! str_starts_with($arg, $prefix)) {
                continue;
            }

            $value = substr($arg, strlen($prefix));
            return $value === '' ? null : $value;
        }

        return null;
    }
}
