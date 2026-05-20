<?php

namespace Admnio\Sunset\Dashboard\Http\Middleware;

use Admnio\Sunset\Manager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on the published Admnio\Sunset\Contracts\* interfaces instead.
 */
class Authorize
{
    public function __construct(private Manager $manager)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        return $this->manager->check($request) ? $next($request) : abort(403);
    }
}
