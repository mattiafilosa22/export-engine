<?php

use App\Http\Controllers\Api\V1\Export\CancelExportController;
use App\Http\Controllers\Api\V1\Export\CreateExportController;
use App\Http\Controllers\Api\V1\Export\DownloadExportController;
use App\Http\Controllers\Api\V1\Export\ShowExportController;
use App\Http\Controllers\Api\V1\Export\PreviewExportController;
use App\Http\Controllers\Api\V1\Ingestion\IngestEventsController;
use App\Http\Controllers\Api\V1\Ingestion\IngestPlayersController;
use App\Http\Controllers\Api\V1\Ingestion\ListPlayersController;
use App\Http\Controllers\Api\V1\Ingestion\ShowImportController;
use App\Http\Controllers\Api\V1\Version\CreateVersionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Rotte v1. Implicit route model binding per uuid (Version/Export) → 404 auto.
|
*/

// `api.key` is a no-op unless GAMINDO_API_KEY is set (machine-to-machine auth).
Route::prefix('v1')->middleware('api.key')->group(function () {
    // Versions (small, synchronous create).
    Route::post('versions', CreateVersionController::class)->name('versions.store');

    // Ingestion (hybrid: sync validation at the boundary, async chunked writes).
    Route::post('versions/{version}/players', IngestPlayersController::class)->name('imports.players.store');
    Route::get('versions/{version}/players', ListPlayersController::class)->name('players.index');
    Route::post('versions/{version}/events', IngestEventsController::class)->name('imports.events.store');
    Route::get('imports/{import}', ShowImportController::class)->name('imports.show');

    // Export.
    Route::post('versions/{version}/exports', CreateExportController::class)->name('exports.store');
    Route::post('versions/{version}/exports/preview', PreviewExportController::class)->name('exports.preview');
    Route::get('exports/{export}', ShowExportController::class)->name('exports.show');
    Route::post('exports/{export}/cancel', CancelExportController::class)->name('exports.cancel');
    Route::get('exports/{export}/download', DownloadExportController::class)->name('exports.download');
});
