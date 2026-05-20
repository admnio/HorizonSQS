<?php

namespace Admnio\Sunset\Dashboard\Http\Controllers;

use Illuminate\Bus\BatchRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Response as InertiaResponse;
use Throwable;

/**
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on the published Admnio\Sunset\Contracts\* interfaces instead.
 */
final class BatchesController extends Controller
{
    public function show(Request $request): InertiaResponse|JsonResponse
    {
        $batches    = [];
        $configured = true;

        // Batches are an optional Laravel feature - the BatchRepository
        // binding only exists when bus/batches are configured. Resolve it
        // defensively so the dashboard page still renders an empty list
        // (and a configuration hint) on installations without batches.
        try {
            $repo    = app(BatchRepository::class);
            $batches = $repo->get(null, 50);
        } catch (Throwable) {
            $configured = false;
        }

        return $this->inertiaOrJson($request, 'Sunset/Batches', [
            'batches'    => $batches,
            'configured' => $configured,
        ]);
    }
}
