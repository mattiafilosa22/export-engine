<?php

namespace App\Http\Controllers\Api\V1\Ingestion;

use App\Actions\Ingestion\CreateImportAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ingestion\StoreEventsImportRequest;
use App\Http\Resources\Ingestion\ImportResource;
use App\Models\Import;
use App\Models\Version;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Queues an events batch (append, idempotent) for a version: validates the
 * batch at the boundary, delegates to the Action, responds 202.
 */
class IngestEventsController extends Controller
{
    /**
     * Ingest events
     *
     * Queue an asynchronous append of an events batch. Idempotent on
     * (version_id, dedup_key) when a key is provided; keyless events are
     * always appended.
     *
     * @group Ingestion
     *
     * @urlParam version string required The version UUID. Example: 3f2504e0-4f89-41d3-9a0c-0305e82c3301
     *
     * @bodyParam events object[] required The events to append (max 5000).
     * @bodyParam events[].dedup_key string Optional idempotency key, unique per version. Omit to append. Example: evt-1
     * @bodyParam events[].player_id integer required The player's id in the version. Example: 42
     * @bodyParam events[].player_email string Optional fallback identifier. Example: x@a.com
     * @bodyParam events[].type string required The event type. Example: transaction
     * @bodyParam events[].occurred_at string required When the event occurred. Example: 2026-01-15T10:00:00Z
     * @bodyParam events[].payload object Event data; also feeds the typed row. Example: {"amount":9.99}
     *
     * @response 202 {"data":{"id":"9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d","type":"events","status":"pending"}}
     * @response 413 {"message":"Batch too large."}
     * @response 422 {"message":"The given data was invalid.","errors":{"events.0.player_id":["Required."]}}
     * @response 404 {"message":"No query results for model [App\\Models\\Version]."}
     */
    public function __invoke(
        StoreEventsImportRequest $request,
        Version $version,
        CreateImportAction $action
    ): JsonResponse {
        $import = $action->execute($version, Import::TYPE_EVENTS, $request->events());

        return ImportResource::make($import)
            ->response()
            ->setStatusCode(Response::HTTP_ACCEPTED);
    }
}
