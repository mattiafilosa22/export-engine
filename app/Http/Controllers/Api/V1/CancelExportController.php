<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Export\CancelExportAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\ExportResource;
use App\Models\Export;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Requests cancellation of an export: delegates to the Action, responds 202
 * (or 409 if the export is already finished).
 */
class CancelExportController extends Controller
{
    /**
     * Cancel an export
     *
     * Request cancellation. A pending export is cancelled immediately; a
     * processing one is stopped at the next chunk. A finished export → 409.
     *
     * @group Exports
     *
     * @urlParam export string required The export UUID. Example: 9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d
     *
     * @response 202 {"data":{"id":"9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d","status":"cancelled"}}
     * @response 409 {"message":"Export cannot be cancelled."}
     * @response 404 {"message":"No query results for model [App\\Models\\Export]."}
     */
    public function __invoke(Export $export, CancelExportAction $action): JsonResponse
    {
        if (! $action->execute($export)) {
            abort(Response::HTTP_CONFLICT, 'Export cannot be cancelled.');
        }

        return ExportResource::make($export->refresh())
            ->response()
            ->setStatusCode(Response::HTTP_ACCEPTED);
    }
}
