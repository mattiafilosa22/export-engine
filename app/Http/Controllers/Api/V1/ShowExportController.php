<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ExportResource;
use App\Models\Export;

/**
 * Returns the durable state of an export (implicit binding by uuid → auto 404).
 */
class ShowExportController extends Controller
{
    /**
     * Show an export
     *
     * Return the current durable state of an export.
     *
     * @group Exports
     *
     * @urlParam export string required The export UUID. Example: 9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d
     *
     * @response 200 {"data":{"id":"9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d","status":"completed","format":"xlsx","progress":100,"total_rows":3000}}
     * @response 404 {"message":"No query results for model [App\\Models\\Export]."}
     */
    public function __invoke(Export $export): ExportResource
    {
        return ExportResource::make($export);
    }
}
