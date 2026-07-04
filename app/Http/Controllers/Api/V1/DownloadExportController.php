<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Export;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Filesystem\FilesystemAdapter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves the generated XLSX file. Guard clause: 409 if not `completed`,
 * 404 if the domain file is missing on disk.
 */
class DownloadExportController extends Controller
{
    private const CONTENT_TYPE_XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    public function __invoke(Export $export, FilesystemFactory $filesystem): StreamedResponse
    {
        if (! $export->isCompleted()) {
            abort(409, 'Export is not completed.');
        }

        $disk = $filesystem->disk('local');

        if (! $disk instanceof FilesystemAdapter) {
            abort(500, 'Export disk is not available.');
        }

        if ($export->file_path === null || ! $disk->exists($export->file_path)) {
            abort(404, 'Export file not found.');
        }

        return $disk->download($export->file_path, "export-{$export->uuid}.xlsx", [
            'Content-Type' => self::CONTENT_TYPE_XLSX,
        ]);
    }
}
