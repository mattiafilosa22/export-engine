<?php

namespace App\Http\Controllers\Api\V1\Ingestion;

use App\Actions\Ingestion\CreateImportAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ingestion\StoreRewardsImportRequest;
use App\Http\Resources\Ingestion\ImportResource;
use App\Models\Import;
use App\Models\Version;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Queues a rewards batch (append, idempotent) for a version: validates the
 * batch at the boundary, delegates to the Action, responds 202. Direct
 * alternative to the event-driven `reward_granted` event type.
 */
class IngestRewardsController extends Controller
{
    /**
     * Ingest rewards
     *
     * Queue an asynchronous append of a rewards batch. Idempotent on
     * (version_id, dedup_key) when a key is provided; keyless rows are always
     * appended. Rows inserted here have no linked event.
     *
     * @group Ingestion
     *
     * @urlParam version string required The version UUID. Example: 3f2504e0-4f89-41d3-9a0c-0305e82c3301
     *
     * @bodyParam rewards object[] required The rewards to append (max 5000).
     * @bodyParam rewards[].dedup_key string Optional idempotency key, unique per version. Example: rwd-1
     * @bodyParam rewards[].player_id integer required The player's id in the version. Example: 42
     * @bodyParam rewards[].player_email string Optional fallback identifier. Example: x@a.com
     * @bodyParam rewards[].reward_type string required The reward type. Example: coupon
     * @bodyParam rewards[].reward_code string Optional reward code. Example: XMAS10
     * @bodyParam rewards[].status string One of granted, redeemed, expired (default granted). Example: granted
     * @bodyParam rewards[].granted_at string required When the reward was granted. Example: 2026-01-15T10:00:00Z
     * @bodyParam rewards[].redeemed_at string Optional redemption timestamp. Example: 2026-01-20T10:00:00Z
     *
     * @response 202 {"data":{"id":"9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d","type":"rewards","status":"pending"}}
     * @response 413 {"message":"Batch too large."}
     * @response 422 {"message":"The given data was invalid.","errors":{"rewards.0.reward_type":["Required."]}}
     * @response 404 {"message":"No query results for model [App\\Models\\Version]."}
     */
    public function __invoke(
        StoreRewardsImportRequest $request,
        Version $version,
        CreateImportAction $action
    ): JsonResponse {
        $import = $action->execute($version, Import::TYPE_REWARDS, $request->rewards());

        return ImportResource::make($import)
            ->response()
            ->setStatusCode(Response::HTTP_ACCEPTED);
    }
}
