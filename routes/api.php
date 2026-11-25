<?php

use App\Http\Controllers\Api\NetworkMonitorController;
use App\Http\Controllers\Api\V0\GetLatestController;
use Illuminate\Support\Facades\Route;

/**
 * Health check route to ensure the API is up and running.
 */
Route::get('/healthcheck', function () {
    return response()->json([
        'message' => 'Speedtest Tracker is running!',
    ]);
})->name('healthcheck');

/**
 * This route provides backwards compatibility from https://github.com/henrywhitaker3/Speedtest-Tracker
 * for Homepage and Organizr dashboards which expects the returned
 * download and upload values in mbits.
 *
 * @deprecated
 */
Route::get('/speedtest/latest', GetLatestController::class)
    ->middleware(['throttle:api', 'accept-json'])
    ->name('speedtest.latest');

Route::middleware(['auth:sanctum', 'throttle:api', 'accept-json'])->group(function () {
    require __DIR__.'/api/v1/routes.php';
});

Route::prefix('v2')->middleware(['throttle:api'])->group(function () {
    Route::get('/latest', [\App\Http\Controllers\Api\V2\PublicMetricsController::class, 'latest']);
    Route::get('/history', [\App\Http\Controllers\Api\V2\PublicMetricsController::class, 'history']);
    Route::get('/stats', [\App\Http\Controllers\Api\V2\PublicMetricsController::class, 'stats']);
});
Route::get('/v2/health', fn () => response()->json(['status' => 'ok']))->middleware('throttle:api');

Route::prefix('network-monitor/v1')->withoutMiddleware('throttle:api')->middleware(['throttle:network-monitor', 'accept-json'])->group(function () {
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/devices', [NetworkMonitorController::class, 'devices']);
        Route::get('/rankings', [NetworkMonitorController::class, 'rankings']);
        Route::get('/sources', [NetworkMonitorController::class, 'sources']);
        Route::post('/sources', [NetworkMonitorController::class, 'register']);
        Route::delete('/sources/{id}', [NetworkMonitorController::class, 'revoke'])->whereNumber('id');
    });

    Route::get('/collector/settings', [NetworkMonitorController::class, 'collectorSettings']);
    Route::post('/collector/reports', [NetworkMonitorController::class, 'collect']);
    Route::post('/agent/samples', [NetworkMonitorController::class, 'sample']);
});
