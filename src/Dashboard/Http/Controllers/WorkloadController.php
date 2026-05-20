<?php

namespace Admnio\Sunset\Dashboard\Http\Controllers;

use Admnio\Sunset\Contracts\WorkloadRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Response as InertiaResponse;

/**
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on the published Admnio\Sunset\Contracts\* interfaces instead.
 */
final class WorkloadController extends Controller
{
    public function show(Request $request, WorkloadRepository $workload): InertiaResponse|JsonResponse
    {
        return $this->inertiaOrJson($request, 'Sunset/Workload', [
            'queues' => $workload->get(),
        ]);
    }
}
