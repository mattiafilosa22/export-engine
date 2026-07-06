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
     * Fully client-configurable: choose sheets, columns, filters, sorting, aggregations and a date range.
     * All fields are optional (sensible defaults apply); omit `sheets` for a default events sheet.
     *
     * @group Exports
     *
     * @urlParam version string required The version UUID. Example: 3f2504e0-4f89-41d3-9a0c-0305e82c3301
     *
     * @bodyParam format string Output format. Only `xlsx` is supported. Example: xlsx
     * @bodyParam date_from string Start of the date range (events sources). Example: 2026-01-01
     * @bodyParam date_to string End of the date range (events sources). Example: 2026-01-31
     * @bodyParam sheets object[] The sheets to produce (all fields optional).
     * @bodyParam sheets[].name string Sheet name; also selects the source (players, events_summary). Example: players
     * @bodyParam sheets[].source string Explicit source override (events, players). Example: events
     * @bodyParam sheets[].columns mixed[] Field aliases, or {field, as}, or {fn, field, as}.
     * @bodyParam sheets[].metrics string[] Named metrics: count, unique_players, avg_score.
     * @bodyParam sheets[].group_by string[] Field aliases (dot-notation like payload.language allowed).
     * @bodyParam sheets[].filters object Whitelisted filters, e.g. {"language":"it"}.
     * @bodyParam sheets[].sort string[] Sort as "column:direction" (e.g. "registered_at:desc") or objects.
     *
     * @response 202 {"data":{"id":"9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d","status":"pending","format":"xlsx"}}
     * @response 404 {"message":"No query results for model [App\\Models\\Version]."}
     * @response 422 {"message":"The given data was invalid.","errors":{"sheets.0.metrics":["Unsupported metric 'x'."]}}
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
