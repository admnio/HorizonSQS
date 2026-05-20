<?php

namespace Admnio\Sunset\Dashboard\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as LaravelController;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on the published Admnio\Sunset\Contracts\* interfaces instead.
 */
abstract class Controller extends LaravelController
{
    /**
     * Render the page via Inertia for a full navigation, or return the same
     * props as JSON when the SPA polls the route with ?refresh=1 (or anything
     * else that signals an XHR). This is the foundation of the "same-route
     * polling" pattern used throughout the dashboard.
     */
    protected function inertiaOrJson(Request $request, string $page, array $props): InertiaResponse|JsonResponse
    {
        if ($request->query('refresh') === '1' || $request->wantsJson()) {
            return response()->json(['props' => $props]);
        }

        return Inertia::render($page, $props);
    }
}
