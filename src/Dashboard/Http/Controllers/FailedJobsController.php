<?php

namespace Admnio\Sunset\Dashboard\Http\Controllers;

use Admnio\Sunset\Contracts\FailedJobRepository;
use Admnio\Sunset\Contracts\JobRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Response as InertiaResponse;

/**
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on the published Admnio\Sunset\Contracts\* interfaces instead.
 */
final class FailedJobsController extends Controller
{
    public function show(Request $request, FailedJobRepository $store): InertiaResponse|JsonResponse
    {
        return $this->inertiaOrJson($request, 'Sunset/Failed', [
            'failures' => $store->getFailed()->all(),
            'total'    => $store->totalFailed(),
            'recent'   => $store->countRecentlyFailed(),
        ]);
    }

    public function retry(string $id, FailedJobRepository $store, JobRepository $jobs): JsonResponse
    {
        $record = $store->findFailed($id);
        if (! $record) {
            return response()->json(['error' => 'not_found'], 404);
        }

        // findFailed() returns an object with payload/queue/connection fields.
        // The payload is JSON-encoded for the underlying queue driver.
        $payload    = $record->payload    ?? null;
        $queue      = $record->queue      ?? 'default';
        $connection = $record->connection ?? 'redis';

        $rawPayload = is_string($payload) ? $payload : json_encode($payload ?? []);

        app('queue')->connection($connection)->pushRaw($rawPayload, $queue);

        // Cross-reference the new retry on the failed record so the dashboard
        // can render a retry chain. The retry id is a fresh UUID; the worker
        // will publish events under it when the job is reserved/completed.
        $retryId = (string) Str::uuid();
        $jobs->storeRetryReference($id, $retryId);

        return response()->json(['retried' => true, 'retry_id' => $retryId]);
    }

    public function retryBulk(Request $request, FailedJobRepository $store, JobRepository $jobs): JsonResponse
    {
        $ids = (array) $request->input('ids', []);
        $out = [];
        foreach ($ids as $id) {
            $resp     = $this->retry((string) $id, $store, $jobs);
            $out[$id] = $resp->getData(true);
        }

        return response()->json($out);
    }

    public function delete(string $id, FailedJobRepository $store): JsonResponse
    {
        $store->deleteFailed($id);

        return response()->json(['deleted' => true]);
    }

    public function deleteBulk(Request $request, FailedJobRepository $store): JsonResponse
    {
        $ids = (array) $request->input('ids', []);
        foreach ($ids as $id) {
            $store->deleteFailed((string) $id);
        }

        return response()->json(['deleted' => count($ids)]);
    }
}
