<?php

namespace Admnio\Sunset\Dashboard\Http\Controllers;

use Admnio\Sunset\Contracts\ActivityRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Response as InertiaResponse;

/**
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on the published Admnio\Sunset\Contracts\* interfaces instead.
 *
 * Routes (all under the dashboard prefix, behind Authorize middleware):
 *   GET /activity        → show()   Inertia page + ?refresh=1 JSON props
 *   GET /activity/page   → page()   JSON paginate "load older" via before_id
 *
 * v1.2.1: the SSE stream endpoint was removed. The page polls the same route
 * with ?refresh=1 every tick like every other dashboard page does, which kept
 * the design uniform and eliminated the Octane worker-starvation tradeoff.
 */
final class ActivityController extends Controller
{
    /**
     * Cap on the rows surfaced to the Activity page on each render / poll.
     * The Vue page caps its in-memory ring buffer at 1000 entries; serving
     * 200 keeps the polled payload small while leaving room to scroll back
     * via "Load older" pagination.
     */
    private const PAGE_LIMIT = 200;

    public function __construct(
        private readonly ActivityRepository $repository,
    ) {
    }

    public function show(Request $request): InertiaResponse|JsonResponse
    {
        $events = $this->repository->recent(self::PAGE_LIMIT);

        $props = [
            // toArray() emits the canonical snake_case shape consumed by the
            // Vue page (matches ActivityEvent::toArray + the JSON page reply).
            'events' => array_map(static fn ($e) => $e->toArray(), $events),
            'enabled' => (bool) config('sunset.activity.enabled', true),
            // Pre-built absolute URL for "Load older" pagination — honours a
            // customised dashboard prefix (SUNSET_PATH).
            'page_url' => $this->urlFor('page'),
        ];

        return $this->inertiaOrJson($request, 'Activity', $props);
    }

    /**
     * "Load older" pagination for the Activity log. Returns up to PAGE_LIMIT
     * events strictly older than ?before_id, descending — same ordering as
     * show()'s props so the client can splice the response onto the tail of
     * its in-memory event list without re-sorting.
     */
    public function page(Request $request): JsonResponse
    {
        // PHP_INT_MAX as the default cursor means "everything from the
        // newest end of the buffer" — same effective behaviour as recent().
        $beforeId = (int) $request->query('before_id', (string) PHP_INT_MAX);

        $events = $this->repository->before($beforeId, self::PAGE_LIMIT);

        return response()->json([
            'events' => array_map(static fn ($e) => $e->toArray(), $events),
        ]);
    }

    /**
     * Absolute URL to an Activity sub-route, built from the configured
     * dashboard prefix. Both Inertia branches emit the same URLs so the SPA
     * doesn't have to re-derive the prefix client-side (it may have been
     * customised via SUNSET_PATH).
     */
    private function urlFor(string $suffix): string
    {
        $prefix = trim((string) config('sunset.dashboard.path', config('sunset.path', 'sunset')), '/');

        return url($prefix . '/activity/' . $suffix);
    }
}
