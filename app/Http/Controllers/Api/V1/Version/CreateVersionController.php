<?php

namespace App\Http\Controllers\Api\V1\Version;

use App\Actions\Version\CreateVersionAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Version\StoreVersionRequest;
use App\Http\Resources\Version\VersionResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Creates a version synchronously: validates, delegates to the Action, responds 201.
 */
class CreateVersionController extends Controller
{
    /**
     * Create a version
     *
     * Create a campaign/game version. Small synchronous operation; the uuid is
     * generated server-side. Only `name` is required.
     *
     * @group Versions
     *
     * @bodyParam name string required The version name. Example: Summer Campaign 2026
     * @bodyParam client_name string The client the version belongs to. Example: Acme Inc.
     * @bodyParam status string One of draft, active, archived (default draft). Example: active
     * @bodyParam starts_at string Campaign start (date/time). Example: 2026-06-01
     * @bodyParam ends_at string Campaign end (date/time, >= starts_at). Example: 2026-08-31
     * @bodyParam config object Free-form campaign configuration.
     *
     * @response 201 {"data":{"uuid":"3f2504e0-4f89-41d3-9a0c-0305e82c3301","name":"Summer Campaign","status":"draft"}}
     * @response 422 {"message":"The given data was invalid.","errors":{"name":["The name field is required."]}}
     */
    public function __invoke(StoreVersionRequest $request, CreateVersionAction $action): JsonResponse
    {
        $version = $action->execute($request->validated());

        return VersionResource::make($version)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
