<?php

namespace Admnio\Sunset\Dashboard\Http\Controllers;

use Admnio\Sunset\Contracts\MasterSupervisorRepository;
use Admnio\Sunset\Contracts\ProcessRepository;
use Admnio\Sunset\Contracts\SupervisorCommandQueue;
use Admnio\Sunset\Contracts\SupervisorRepository;
use Admnio\Sunset\Contracts\WorkerMetricsRepository;
use Admnio\Sunset\SupervisorCommands\ContinueWorking;
use Admnio\Sunset\SupervisorCommands\Pause;
use Admnio\Sunset\SupervisorCommands\Scale;
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

    /**
     * Push a Scale command onto the named supervisor's command queue so the
     * supervisor's next loop tick adjusts its worker count without restarting.
     *
     * Operators driving this from the dashboard +/− buttons can only ever
     * submit small deltas, but we still clamp to [1, 256] server-side so a
     * crafted request can't request 10k workers or a negative count. We
     * deliberately disallow 0 here — operators should use pause() for that.
     */
    public function scale(string $name, Request $request, SupervisorCommandQueue $commands): JsonResponse
    {
        $processes = max(1, min((int) $request->input('processes', 0), 256));

        $commands->push($name, Scale::class, ['processes' => $processes]);

        return response()->json([
            'ok'         => true,
            'command'    => 'scale',
            'supervisor' => $name,
            'processes'  => $processes,
        ]);
    }

    public function processes(string $master, ProcessRepository $processes): JsonResponse
    {
        return response()->json([
            'master'   => $master,
            'orphans'  => $processes->allOrphans($master),
        ]);
    }
}
