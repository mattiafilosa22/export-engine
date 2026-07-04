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
