<?php

use App\Http\Controllers\Api\V1\CreateExportController;
use App\Http\Controllers\Api\V1\DownloadExportController;
use App\Http\Controllers\Api\V1\ShowExportController;
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
    Route::post('versions/{version}/exports', CreateExportController::class)->name('exports.store');
    Route::get('exports/{export}', ShowExportController::class)->name('exports.show');
    Route::get('exports/{export}/download', DownloadExportController::class)->name('exports.download');
});
