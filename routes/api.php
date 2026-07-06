<?php

use App\Http\Controllers\Api\V1\CancelExportController;
use App\Http\Controllers\Api\V1\CreateExportController;
use App\Http\Controllers\Api\V1\DownloadExportController;
use App\Http\Controllers\Api\V1\IngestEventsController;
use App\Http\Controllers\Api\V1\IngestPlayersController;
use App\Http\Controllers\Api\V1\ShowExportController;
use App\Http\Controllers\Api\V1\ShowImportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Rotte v1. Implicit route model binding per uuid (Version/Export) → 404 auto.
|
*/

Route::prefix('v1')->group(function () {
    // Ingestion (hybrid: sync validation at the boundary, async chunked writes).
    Route::post('versions/{version}/players', IngestPlayersController::class)->name('imports.players.store');
    Route::post('versions/{version}/events', IngestEventsController::class)->name('imports.events.store');
    Route::get('imports/{import}', ShowImportController::class)->name('imports.show');

    // Export.
    Route::post('versions/{version}/exports', CreateExportController::class)->name('exports.store');
    Route::get('exports/{export}', ShowExportController::class)->name('exports.show');
    Route::post('exports/{export}/cancel', CancelExportController::class)->name('exports.cancel');
    Route::get('exports/{export}/download', DownloadExportController::class)->name('exports.download');
});
