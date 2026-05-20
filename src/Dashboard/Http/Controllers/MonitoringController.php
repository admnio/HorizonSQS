<?php

namespace Admnio\Sunset\Dashboard\Http\Controllers;

use Admnio\Sunset\Contracts\TagRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Response as InertiaResponse;

/**
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on the published Admnio\Sunset\Contracts\* interfaces instead.
 */
final class MonitoringController extends Controller
{
    public function show(Request $request, TagRepository $tags): InertiaResponse|JsonResponse
    {
        $pinned = $tags->monitored();

        // Per-tag job count, useful for the index page.
        $counts = [];
        foreach ($pinned as $tag) {
            $counts[$tag] = $tags->count($tag);
        }

        return $this->inertiaOrJson($request, 'Sunset/Monitoring', [
            'pinned' => $pinned,
            'counts' => $counts,
        ]);
    }

    public function jobsForTag(string $tag, Request $request, TagRepository $tags): JsonResponse
    {
        $afterIndex = $request->query('after');
        $afterIndex = $afterIndex !== null ? (string) $afterIndex : null;

        return response()->json([
            'tag'   => $tag,
            'count' => $tags->count($tag),
            'jobs'  => $tags->jobs($tag, $afterIndex)->all(),
        ]);
    }

    public function pin(string $tag, TagRepository $tags): JsonResponse
    {
        $tags->monitor($tag);

        return response()->json(['pinned' => true]);
    }

    public function unpin(string $tag, TagRepository $tags): JsonResponse
    {
        $tags->stopMonitoring($tag);

        return response()->json(['unpinned' => true]);
    }
}
