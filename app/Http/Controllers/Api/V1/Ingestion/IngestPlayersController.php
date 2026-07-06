<?php

namespace App\Http\Controllers\Api\V1\Ingestion;

use App\Actions\Ingestion\CreateImportAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ingestion\StorePlayersImportRequest;
use App\Http\Resources\Ingestion\ImportResource;
use App\Models\Import;
use App\Models\Version;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Queues a players batch (upsert) for a version: validates the batch at the
 * boundary, delegates to the Action, responds 202.
 */
class IngestPlayersController extends Controller
{
    /**
     * Ingest players
     *
     * Queue an asynchronous upsert of a players batch. The batch is validated
     * synchronously (structure + size) and written in the background.
     *
     * @group Ingestion
     *
     * @urlParam version string required The version UUID. Example: 3f2504e0-4f89-41d3-9a0c-0305e82c3301
     *
     * @bodyParam players object[] required The players to upsert (max 5000).
     * @bodyParam players[].email string required The person's email (unique identity). Example: mario@example.com
     * @bodyParam players[].external_id string The external SSO id. Example: sso-123
     * @bodyParam players[].registered_at string The registration timestamp. Example: 2026-01-15T10:00:00Z
     * @bodyParam players[].language string The player language. Example: it
     *
     * @response 202 {"data":{"id":"9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d","type":"players","status":"pending","total_rows":2}}
     * @response 413 {"message":"Batch too large."}
     * @response 422 {"message":"The given data was invalid.","errors":{"players.0.email":["The email is required."]}}
     * @response 404 {"message":"No query results for model [App\\Models\\Version]."}
     */
    public function __invoke(
        StorePlayersImportRequest $request,
        Version $version,
        CreateImportAction $action
    ): JsonResponse {
        $import = $action->execute($version, Import::TYPE_PLAYERS, $request->players());

        return ImportResource::make($import)
            ->response()
            ->setStatusCode(Response::HTTP_ACCEPTED);
    }
}
