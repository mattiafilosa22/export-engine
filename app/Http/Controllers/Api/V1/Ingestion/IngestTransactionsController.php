<?php

namespace App\Http\Controllers\Api\V1\Ingestion;

use App\Actions\Ingestion\CreateImportAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ingestion\StoreTransactionsImportRequest;
use App\Http\Resources\Ingestion\ImportResource;
use App\Models\Import;
use App\Models\Version;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Queues a transactions batch (append, idempotent) for a version: validates the
 * batch at the boundary, delegates to the Action, responds 202. Direct
 * alternative to the event-driven `transaction` event type.
 */
class IngestTransactionsController extends Controller
{
    /**
     * Ingest transactions
     *
     * Queue an asynchronous append of a transactions batch. Idempotent on
     * (version_id, dedup_key) when a key is provided; keyless rows are always
     * appended. Rows inserted here have no linked event.
     *
     * @group Ingestion
     *
     * @urlParam version string required The version UUID. Example: 3f2504e0-4f89-41d3-9a0c-0305e82c3301
     *
     * @bodyParam transactions object[] required The transactions to append (max 5000).
     * @bodyParam transactions[].dedup_key string Optional idempotency key, unique per version. Example: txn-1
     * @bodyParam transactions[].player_id integer required The player's id in the version. Example: 42
     * @bodyParam transactions[].player_email string Optional fallback identifier. Example: x@a.com
     * @bodyParam transactions[].type string required One of purchase, spend, refund. Example: purchase
     * @bodyParam transactions[].amount number required Transaction amount. Example: 9.99
     * @bodyParam transactions[].currency string required 3-letter currency code. Example: EUR
     * @bodyParam transactions[].status string One of pending, completed, failed (default completed). Example: completed
     * @bodyParam transactions[].external_ref string Optional external reference. Example: PAY-123
     * @bodyParam transactions[].occurred_at string required When it occurred. Example: 2026-01-15T10:00:00Z
     *
     * @response 202 {"data":{"id":"9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d","type":"transactions","status":"pending"}}
     * @response 413 {"message":"Batch too large."}
     * @response 422 {"message":"The given data was invalid.","errors":{"transactions.0.amount":["Required."]}}
     * @response 404 {"message":"No query results for model [App\\Models\\Version]."}
     */
    public function __invoke(
        StoreTransactionsImportRequest $request,
        Version $version,
        CreateImportAction $action
    ): JsonResponse {
        $import = $action->execute($version, Import::TYPE_TRANSACTIONS, $request->transactions());

        return ImportResource::make($import)
            ->response()
            ->setStatusCode(Response::HTTP_ACCEPTED);
    }
}
