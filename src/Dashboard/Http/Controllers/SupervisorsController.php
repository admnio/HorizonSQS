<?php

namespace Admnio\Sunset\Dashboard\Http\Controllers;

use Admnio\Sunset\Contracts\MasterSupervisorRepository;
use Admnio\Sunset\Contracts\ProcessRepository;
use Admnio\Sunset\Contracts\SupervisorCommandQueue;
use Admnio\Sunset\Contracts\SupervisorRepository;
use Admnio\Sunset\Contracts\WorkerMetricsRepository;
use Admnio\Sunset\SupervisorCommands\ContinueWorking;
use Admnio\Sunset\SupervisorCommands\Pause;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Response as InertiaResponse;

/**
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on the published Admnio\Sunset\Contracts\* interfaces instead.
 */
final class SupervisorsController extends Controller
{
    /**
     * Points of sparkline history surfaced per PID. 20 × 5s ≈ 100 seconds of
     * recent history — enough to spot a trend without bloating the JSON
     * payload. The repository keeps a larger window (telemetry.series_points)
     * server-side so future UIs can ask for more.
     */
    private const SPARKLINE_POINTS = 20;

    public function show(
        Request $request,
        SupervisorRepository $supervisors,
        MasterSupervisorRepository $masters,
        WorkerMetricsRepository $workerMetrics,
    ): InertiaResponse|JsonResponse {
        return $this->inertiaOrJson(
            $request,
            'Sunset/Supervisors',
            $this->payload($supervisors, $masters, $workerMetrics),
        );
    }

    /**
     * Build the shared prop set for both the initial Inertia render and the
     * ?refresh=1 JSON branch. Factored out so both paths emit the same
     * top-level prop keys (PollingShapeContractTest guards against drift).
     *
     * @return array<string, mixed>
     */
    private function payload(
        SupervisorRepository $supervisors,
        MasterSupervisorRepository $masters,
        WorkerMetricsRepository $workerMetrics,
    ): array {
        $snapshots = $workerMetrics->all();

        $metricsByPid = [];
        $seriesByPid = [];
        foreach ($snapshots as $snapshot) {
            $pid = $snapshot->pid;
            $metricsByPid[$pid] = $snapshot->toArray();
            $seriesByPid[$pid] = [
                'rss' => $workerMetrics->series($pid, 'rss', self::SPARKLINE_POINTS),
                'cpu' => $workerMetrics->series($pid, 'cpu', self::SPARKLINE_POINTS),
            ];
        }

        return [
            'supervisors'          => $supervisors->all(),
            'masters'              => $masters->all(),
            'worker_metrics'       => $metricsByPid,
            'worker_metric_series' => $seriesByPid,
        ];
    }

    /**
     * Push a Pause command onto the named supervisor's command queue. The
     * supervisor's main loop drains that queue every tick and processes
     * commands by class, so the value sent here MUST be the FQCN of the
     * Pause command class (not a `{type: pause}` shape).
     */
    public function pause(string $name, SupervisorCommandQueue $commands): JsonResponse
    {
        $commands->push($name, Pause::class);

        return response()->json(['ok' => true, 'command' => 'pause', 'supervisor' => $name]);
    }

    public function resume(string $name, SupervisorCommandQueue $commands): JsonResponse
    {
        $commands->push($name, ContinueWorking::class);

        return response()->json(['ok' => true, 'command' => 'continue', 'supervisor' => $name]);
    }

    public function processes(string $master, ProcessRepository $processes): JsonResponse
    {
        return response()->json([
            'master'   => $master,
            'orphans'  => $processes->allOrphans($master),
        ]);
    }
}
