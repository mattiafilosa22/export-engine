<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ImportResource;
use App\Models\Import;

/**
 * Returns the durable state of an import (implicit binding by uuid → auto 404).
 */
class ShowImportController extends Controller
{
    /**
     * Show an import
     *
     * Return the current durable state and counters of an ingestion job.
     *
     * @group Ingestion
     *
     * @urlParam import string required The import UUID. Example: 9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d
     *
     * @response 200 {"data":{"id":"9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d","type":"events","status":"completed","total_rows":100,"processed_rows":100,"inserted":98,"duplicates":0,"failed":2}}
     * @response 404 {"message":"No query results for model [App\\Models\\Import]."}
     */
    public function __invoke(Import $import): ImportResource
    {
        return ImportResource::make($import);
    }
}
