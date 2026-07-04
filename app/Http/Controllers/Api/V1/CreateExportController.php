<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Export\CreateExportAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreExportRequest;
use App\Http\Resources\ExportResource;
use App\Models\Version;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Queues an export for a version: validates, delegates to the Action, responds 202.
 */
class CreateExportController extends Controller
{
    /**
     * Create an export
     *
     * Queue an asynchronous XLSX export for a version. Returns 202 with the export in `pending` state.
     *
     * @group Exports
     *
     * @urlParam version string required The version UUID. Example: 3f2504e0-4f89-41d3-9a0c-0305e82c3301
     *
     * @bodyParam format string The output format. Only `xlsx` is supported. Example: xlsx
     * @bodyParam params object Optional export parameters (sheets, columns, filters).
     *
     * @response 202 {"data":{"id":"9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d","status":"pending","format":"xlsx"}}
     * @response 404 {"message":"No query results for model [App\\Models\\Version]."}
     * @response 422 {"message":"The given data was invalid.","errors":{"format":["The selected format is invalid."]}}
     */
    public function __invoke(
        StoreExportRequest $request,
        Version $version,
        CreateExportAction $action
    ): JsonResponse {
        $export = $action->execute(
            $version,
            $request->exportParams(),
            $request->exportFormat()
        );

        return ExportResource::make($export)
            ->response()
            ->setStatusCode(Response::HTTP_ACCEPTED);
    }
}
