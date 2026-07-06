<?php

namespace App\Http\Controllers\Api\V1\Export;

use App\Http\Controllers\Controller;
use App\Models\Export;
use App\Support\Export\ExportStorage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves the generated XLSX file. Guard clause: 409 if not `completed`,
 * 404 if the file is missing on disk.
 */
class DownloadExportController extends Controller
{
    /**
     * Download an export file
     *
     * Stream the generated XLSX file. Available only when the export is `completed`.
     *
     * @group Exports
     *
     * @urlParam export string required The export UUID. Example: 9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d
     *
     * @response 409 {"message":"Export is not completed."}
     * @response 404 {"message":"Export file not found."}
     */
    public function __invoke(Export $export, ExportStorage $storage): StreamedResponse
    {
        if (! $export->isCompleted()) {
            abort(409, 'Export is not completed.');
        }

        if (! $storage->exists($export)) {
            abort(404, 'Export file not found.');
        }

        return $storage->download($export);
    }
}
