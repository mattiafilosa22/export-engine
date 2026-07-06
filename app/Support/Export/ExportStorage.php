<?php

namespace App\Support\Export;

use App\Models\Export;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Filesystem\FilesystemAdapter;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Single place that knows the on-disk lifecycle of an export file (path, write
 * directory, size, existence, delete, download) on the local disk. Shared by the
 * generation job and the download endpoint so filesystem details live in one class.
 */
class ExportStorage
{
    private const CONTENT_TYPE_XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    /** @var FilesystemAdapter */
    private $disk;

    public function __construct(FilesystemFactory $filesystem)
    {
        $disk = $filesystem->disk('local');
        if (! $disk instanceof FilesystemAdapter) {
            throw new RuntimeException('Export disk must support absolute filesystem paths.');
        }

        $this->disk = $disk;
    }

    /**
     * Absolute path to write to, creating the export directory if missing.
     */
    public function preparePath(Export $export): string
    {
        $relativePath = $export->relativeFilePath();
        $this->disk->makeDirectory(dirname($relativePath));

        return $this->disk->path($relativePath);
    }

    public function size(Export $export): int
    {
        return (int) $this->disk->size($export->relativeFilePath());
    }

    public function exists(Export $export): bool
    {
        return $this->disk->exists($export->relativeFilePath());
    }

    public function delete(Export $export): void
    {
        if ($this->exists($export)) {
            $this->disk->delete($export->relativeFilePath());
        }
    }

    public function download(Export $export): StreamedResponse
    {
        return $this->disk->download(
            $export->relativeFilePath(),
            "export-{$export->uuid}.xlsx",
            ['Content-Type' => self::CONTENT_TYPE_XLSX]
        );
    }
}
