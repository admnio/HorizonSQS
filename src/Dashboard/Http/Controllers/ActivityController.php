<?php

namespace Admnio\Sunset\Dashboard\Http\Controllers;

use Admnio\Sunset\Activity\ActivityStreamer;
use Admnio\Sunset\Contracts\ActivityRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on the published Admnio\Sunset\Contracts\* interfaces instead.
 *
 * Routes (all under the dashboard prefix, behind Authorize middleware):
 *   GET /activity         → show()    Inertia page + ?refresh=1 JSON props
 *   GET /activity/page    → page()    JSON paginate "load older" via before_id
 *   GET /activity/stream  → stream()  Server-Sent Events (long-lived response)
 *
 * The stream() endpoint is the one piece of the dashboard that holds a request
 * worker for up to sunset.activity.max_connection_seconds. See the README's
 * Octane note: under Swoole/RoadRunner this consumes a worker slot per
 * connected dashboard tab; FPM or disabling the feature are the two escape
 * hatches.
 */
final class ActivityController extends Controller
{
    /**
     * Cap on the rows surfaced to the Activity page initial render. The Vue
     * page caps its in-memory ring buffer at 1000 entries; serving 200 on
     * first render keeps the Inertia payload small without leaving the
     * "Load older" button immediately reachable.
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
            // Vue page (matches ActivityEvent::toArray + the SSE data field).
            'events' => array_map(static fn ($e) => $e->toArray(), $events),
            'enabled' => (bool) config('sunset.activity.enabled', true),
            // Pre-built absolute URL so the Vue page can construct an
            // EventSource without re-deriving the dashboard prefix on the
            // client side (it may have been customised via SUNSET_PATH).
            'stream_url' => $this->urlFor('stream'),
            // Pre-built absolute URL for "Load older" paginate; honors a
            // customised dashboard prefix the same way stream_url does.
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
     * Long-lived Server-Sent Events response. The streamer is resolved fresh
     * per request (bound `bind()` not `singleton()` in the SP) because its
     * clock/sleep/emit closures capture per-response state we don't want
     * leaking across connections.
     *
     * Returns 404 when activity is disabled in config — same fail-quiet shape
     * the rest of the dashboard uses when an optional subsystem is off.
     */
    public function stream(Request $request): StreamedResponse
    {
        if (! (bool) config('sunset.activity.enabled', true)) {
            abort(404);
        }

        // Honour the standard SSE resume header. Anything non-numeric is
        // ignored — null tells the streamer to start from the live head
        // rather than replay history.
        $lastEventIdHeader = $request->header('Last-Event-ID');
        $lastEventId = $lastEventIdHeader !== null && ctype_digit((string) $lastEventIdHeader)
            ? (int) $lastEventIdHeader
            : null;

        return response()->stream(
            function () use ($lastEventId): void {
                // Disable gzip + put PHP into implicit-flush mode so each
                // emit reaches the client immediately instead of pooling
                // until request teardown. We deliberately do NOT walk
                // ob_end_flush() down to level 0 here: under test the
                // harness wraps sendContent() in its own output buffer to
                // capture streamed bytes, and destroying that buffer
                // erupts as "ob_end_clean(): No buffer to delete". The
                // streamer's emit closure already calls ob_flush() + flush()
                // after each frame, which is enough to push bytes through
                // PHP-FPM in production (the framework's own buffer is the
                // only intermediate buffer to deal with).
                @ini_set('zlib.output_compression', '0');
                @ini_set('output_buffering', 'off');
                @ob_implicit_flush(true);

                // Resolve fresh per request — the streamer's emit closure
                // writes to whatever output buffer is active right now.
                $streamer = app(ActivityStreamer::class);
                $streamer->stream($lastEventId);
            },
            200,
            [
                'Content-Type' => 'text/event-stream; charset=UTF-8',
                // no-cache: don't let intermediate caches replay stale
                // bodies. no-transform: don't let proxies (cough, mod_pagespeed)
                // rewrite or inline the SSE stream. no-store: don't keep a copy.
                'Cache-Control' => 'no-cache, no-transform, no-store',
                // Tell HTTP/1.1 proxies to close after this response, since
                // we're not coming back in the same connection.
                'Connection' => 'close',
                // nginx-specific: prevent the reverse proxy from buffering
                // the response body. Without this nginx accumulates frames
                // until the loop returns and the client sees one giant burst.
                'X-Accel-Buffering' => 'no',
            ]
        );
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
