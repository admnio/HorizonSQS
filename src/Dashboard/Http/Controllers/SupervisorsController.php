<?php

namespace Admnio\Sunset\Dashboard\Http\Controllers;

use Admnio\Sunset\Contracts\MasterSupervisorRepository;
use Admnio\Sunset\Contracts\ProcessRepository;
use Admnio\Sunset\Contracts\SupervisorCommandQueue;
use Admnio\Sunset\Contracts\SupervisorRepository;
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
    public function show(
        Request $request,
        SupervisorRepository $supervisors,
        MasterSupervisorRepository $masters,
    ): InertiaResponse|JsonResponse {
        return $this->inertiaOrJson($request, 'Sunset/Supervisors', [
            'supervisors' => $supervisors->all(),
            'masters'     => $masters->all(),
        ]);
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
