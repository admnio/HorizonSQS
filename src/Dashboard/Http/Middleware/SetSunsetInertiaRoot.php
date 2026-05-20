<?php

namespace Admnio\Sunset\Dashboard\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pin the Inertia root view to Sunset's bundled Blade template for the
 * duration of the dashboard request, without mutating Inertia's global
 * config (which would affect the consumer's whole app). Inertia caches the
 * root view per-instance; since the middleware container is rebuilt on every
 * request, calling setRootView() here is request-scoped.
 */
class SetSunsetInertiaRoot
{
    public function handle(Request $request, Closure $next): Response
    {
        Inertia::setRootView('sunset::sunset-app');

        return $next($request);
    }
}
