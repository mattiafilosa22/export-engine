<?php

namespace App\Http\Controllers\Api\V1\Ingestion;

use App\Actions\Ingestion\CreateImportAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ingestion\StoreAnswersImportRequest;
use App\Http\Resources\Ingestion\ImportResource;
use App\Models\Import;
use App\Models\Version;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Queues an answers batch (append, idempotent) for a version: validates the
 * batch at the boundary, delegates to the Action, responds 202. Direct
 * alternative to the event-driven `answer_submitted` event type.
 */
class IngestAnswersController extends Controller
{
    /**
     * Ingest answers
     *
     * Queue an asynchronous append of an answers batch. Idempotent on the
     * table's natural key (version_id, player_id, question_id) — a resend does
     * not duplicate. Rows inserted here have no linked event.
     *
     * @group Ingestion
     *
     * @urlParam version string required The version UUID. Example: 3f2504e0-4f89-41d3-9a0c-0305e82c3301
     *
     * @bodyParam answers object[] required The answers to append (max 5000).
     * @bodyParam answers[].player_id integer required The player's id in the version. Example: 42
     * @bodyParam answers[].player_email string Optional fallback identifier. Example: x@a.com
     * @bodyParam answers[].question_id integer required The question id. Example: 1
     * @bodyParam answers[].answer_option_id integer The chosen option id (closed question). Example: 3
     * @bodyParam answers[].answer_text string Free text (open question). Example: My answer
     * @bodyParam answers[].occurred_at string required When the answer was submitted. Example: 2026-01-15T10:00:00Z
     *
     * @response 202 {"data":{"id":"9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d","type":"answers","status":"pending"}}
     * @response 413 {"message":"Batch too large."}
     * @response 422 {"message":"The given data was invalid.","errors":{"answers.0.question_id":["Required."]}}
     * @response 404 {"message":"No query results for model [App\\Models\\Version]."}
     */
    public function __invoke(
        StoreAnswersImportRequest $request,
        Version $version,
        CreateImportAction $action
    ): JsonResponse {
        $import = $action->execute($version, Import::TYPE_ANSWERS, $request->answers());

        return ImportResource::make($import)
            ->response()
            ->setStatusCode(Response::HTTP_ACCEPTED);
    }
}
