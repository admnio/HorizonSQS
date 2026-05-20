<?php

namespace Admnio\Sunset\Dashboard\Http\Controllers;

use Admnio\Sunset\Contracts\JobRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Response as InertiaResponse;

/**
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on the published Admnio\Sunset\Contracts\* interfaces instead.
 */
final class PendingJobsController extends Controller
{
    public function show(Request $request, JobRepository $jobs): InertiaResponse|JsonResponse
    {
        return $this->inertiaOrJson($request, 'Sunset/Pending', [
            'jobs'  => $jobs->getPending()->all(),
            'total' => $jobs->countPending(),
        ]);
    }
}
