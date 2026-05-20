<?php

namespace Admnio\Sunset\Dashboard\Http\Controllers;

use Admnio\Sunset\Contracts\JobRepository;
use Admnio\Sunset\Contracts\MasterSupervisorRepository;
use Admnio\Sunset\Contracts\SupervisorRepository;
use Admnio\Sunset\Contracts\WorkloadRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Response as InertiaResponse;

/**
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on the published Admnio\Sunset\Contracts\* interfaces instead.
 */
final class OverviewController extends Controller
{
    public function show(
        Request $request,
        WorkloadRepository $workload,
        SupervisorRepository $supervisors,
        MasterSupervisorRepository $masters,
        JobRepository $jobs,
    ): InertiaResponse|JsonResponse {
        $props = [
            'workload'    => $workload->get(),
            'supervisors' => $supervisors->all(),
            'masters'     => $masters->all(),
            'recent'      => $jobs->getRecent()->all(),
        ];

        return $this->inertiaOrJson($request, 'Sunset/Overview', $props);
    }
}
