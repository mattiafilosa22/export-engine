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
    public function __invoke(Export $export): ExportResource
    {
        return ExportResource::make($export);
    }
}
