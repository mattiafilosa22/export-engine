<?php

namespace App\Http\Controllers\Api\V1\Export;

use App\Actions\Export\PreviewExportAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Export\StoreExportRequest;
use App\Models\Version;
use Illuminate\Http\JsonResponse;

/**
 * Synchronous, bounded preview of an export (max preview_rows rows per sheet):
 * reuses the create-export validation, returns JSON, no job.
 */
class PreviewExportController extends Controller
{
    /**
     * Preview an export
     *
     * Return the first rows (max 100) of each configured sheet as JSON — a quick
     * look before queuing the heavy async XLSX export. Same spec as create export.
     *
     * @group Exports
     *
     * @urlParam version string required The version UUID. Example: 3f2504e0-4f89-41d3-9a0c-0305e82c3301
     *
     * @response 200 {"data":{"sheets":[{"name":"Events","header":["id","type"],"rows":[],"truncated":false}]}}
     * @response 422 {"message":"The given data was invalid."}
     */
    public function __invoke(StoreExportRequest $request, Version $version, PreviewExportAction $action): JsonResponse
    {
        return response()->json([
            'data' => $action->execute($version, $request->exportParams()),
        ]);
    }
}
