<?php

namespace Admnio\Sunset\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Redis\Factory as RedisFactory;

/**
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on the published Admnio\Sunset\Contracts\* interfaces instead.
 *
 * v1.1.0 — safety-net reconciliation for worker telemetry. The Looping
 * listener writes a per-PID snapshot hash with a 30s TTL while series ZSETs
 * carry a 600s TTL. If a worker dies between reports its hash expires first;
 * the PID lingers in the worker_metrics:pids set and the series keys orbit
 * with no anchor. This sweep prunes both. Scheduled every minute.
 */
class SunsetSweepWorkerMetricsCommand extends Command
{
    protected $signature = 'sunset:sweep-worker-metrics';

    protected $description = 'Reconcile the worker-metrics PIDs set against expired snapshot hashes.';

    public function __construct(private RedisFactory $redis)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $conn = $this->redis->connection(config('sunset.redis_connection', 'default'));
        $setKey = $this->key('worker_metrics:pids');

        $pids = (array) ($conn->smembers($setKey) ?: []);

        if (empty($pids)) {
            $this->info('Swept 0 stale worker-metrics entries');
            return self::SUCCESS;
        }

        // Probe each hash. EXISTS on a TTL'd hash that has expired returns 0.
        // Pipeline the EXISTS probes so we make one round-trip for N pids.
        $exists = $conn->pipeline(function ($pipe) use ($pids) {
            foreach ($pids as $pid) {
                $pipe->exists($this->key("worker_metrics:{$pid}"));
            }
        });

        $dead = [];
        foreach ($pids as $i => $pid) {
            if ((int) ($exists[$i] ?? 0) === 0) {
                $dead[] = $pid;
            }
        }

        if (empty($dead)) {
            $this->info('Swept 0 stale worker-metrics entries');
            return self::SUCCESS;
        }

        // Pipeline the cleanup: one SREM with all dead pids + DEL on each
        // orphan series key. Mirrors the rate-limit sweep's pipelining shape.
        $conn->pipeline(function ($pipe) use ($setKey, $dead) {
            $pipe->srem($setKey, ...$dead);
            foreach ($dead as $pid) {
                $pipe->del($this->key("worker_metrics:{$pid}:series:rss"));
                $pipe->del($this->key("worker_metrics:{$pid}:series:cpu"));
            }
        });

        $count = count($dead);
        $this->info("Swept {$count} stale worker-metrics entries");

        return self::SUCCESS;
    }

    private function key(string $name): string
    {
        return config('sunset.key_prefix', 'sunset') . ':' . $name;
    }
}
