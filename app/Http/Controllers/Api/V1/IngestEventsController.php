<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Import\CreateImportAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEventsImportRequest;
use App\Http\Resources\ImportResource;
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
     * @bodyParam events[].player_email string required The player's email (must exist in the version). Example: x@a.com
     * @bodyParam events[].type string required The event type. Example: game_completed
     * @bodyParam events[].occurred_at string required When the event occurred. Example: 2026-01-15T10:00:00Z
     *
     * @response 202 {"data":{"id":"9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d","type":"events","status":"pending"}}
     * @response 413 {"message":"Batch too large."}
     * @response 422 {"message":"The given data was invalid.","errors":{"events.0.player_email":["Required."]}}
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
