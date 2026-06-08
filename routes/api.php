<?php

use App\Http\Controllers\Api\V1\AssetController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\LoopController;
use App\Http\Controllers\Api\V1\OverrideController;
use App\Http\Controllers\Api\V1\SyncController;
use App\Http\Controllers\Api\V1\VaultController;
use App\Http\Controllers\Api\V1\SettingController;
use App\Http\Controllers\Api\V1\FallbackSpotController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| BCC API Routes  (prefix: /api/v1)
|--------------------------------------------------------------------------
|
| Three guard layers:
|   • Billboard devices → Sanctum spot with 'device:*' abilities
|   • Admin dashboard  → Sanctum token with no specific ability restriction
|                        (add admin user auth + abilities if multi-tenant)
|   • Public vault     → no auth; rate-limited
|
*/

Route::prefix('v1')->group(function () {

    // ── Device Sync Endpoints ─────────────────────────────────────────────────
    // Authenticated with long-lived Sanctum tokens provisioned per physical board.

    Route::middleware(['auth:sanctum', 'device.token:device:sync'])
        ->get('/sync', [SyncController::class, 'sync'])
        ->name('sync.index');

    Route::middleware(['auth:sanctum', 'device.token:device:log'])
        ->post('/logs', [SyncController::class, 'storeLogs'])
        ->name('sync.logs');

    Route::middleware(['auth:sanctum', 'device.token:device:sync'])
        ->get('/assets/{assetId}/download', [SyncController::class, 'assetDownload'])
        ->name('sync.asset-download');

    Route::middleware(['auth:sanctum', 'device.token:device:sync'])
        ->get('/assets/{assetId}/serve', [SyncController::class, 'serveAsset'])
        ->name('sync.asset-serve');

    Route::middleware(['auth:sanctum', 'device.token:device:sync'])
        ->get('/playback/next', [SyncController::class, 'nextAsset'])
        ->name('sync.playback-next');

    Route::middleware(['auth:sanctum', 'device.token:device:sync'])
        ->post('/playback/start', [SyncController::class, 'reportPlaybackStart'])
        ->name('sync.playback-start');


    // ── Authentication Routes ─────────────────────────────────────────────────
    Route::post('/login', [App\Http\Controllers\Api\V1\AuthController::class, 'login'])->name('login');

    // Billboard player exchanges its unique password for a device token.
    Route::middleware('throttle:10,1')
        ->post('/device/login', [DeviceController::class, 'login'])
        ->name('device.login');

    // ── Admin Routes ──────────────────────────────────────────────────────────
    // Protected by Sanctum and Admin Token Middleware.

    Route::middleware(['auth:sanctum', 'admin.token'])->prefix('admin')->name('admin.')->group(function () {

        // Devices (board provisioning)
        Route::apiResource('devices', DeviceController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::get('devices/{device}/fallback-spots', [FallbackSpotController::class, 'index'])->name('devices.fallback-spots.index');
        Route::get('devices/{device}/schedule', [DeviceController::class, 'schedule'])->name('devices.schedule');
        Route::put('devices/{device}/loop-order', [DeviceController::class, 'updateLoopOrder'])->name('devices.loop-order');
        Route::get('timeline', [SyncController::class, 'timeline'])->name('timeline');
        
        // Settings
        Route::get('settings', [SettingController::class, 'index'])->name('settings.index');
        Route::put('settings', [SettingController::class, 'update'])->name('settings.update');

        // Loops
        Route::put('loops-reorder', [LoopController::class, 'reorderLoops'])->name('loops.reorder-all');
        Route::apiResource('loops', LoopController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::put('loops/{loop}/reorder', [LoopController::class, 'reorder'])->name('loops.reorder');

        // Assets
        Route::apiResource('assets', AssetController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::post('assets/presign', [AssetController::class, 'presign'])->name('assets.presign');
        Route::post('assets/confirm', [AssetController::class, 'confirm'])->name('assets.confirm');

        // Override Protocol (Play Next)
        Route::post('overrides', [OverrideController::class, 'store'])->name('overrides.store');

        // Secure Vault links (admin management)
        Route::get('vault/links',         [VaultController::class, 'index'])->name('vault.links.index');
        Route::post('vault/links',        [VaultController::class, 'store'])->name('vault.links.store');
        Route::delete('vault/links/{link}', [VaultController::class, 'destroy'])->name('vault.links.destroy');

        // System Health
        Route::get('health', [\App\Http\Controllers\Api\V1\HealthController::class, 'index'])->name('health');
    });

    // ── Public Vault Verification ─────────────────────────────────────────────
    // Rate-limited to prevent PIN brute-forcing.

    Route::middleware('throttle:10,1')   // 10 attempts per minute per IP
        ->post('/vault/verify', [VaultController::class, 'verify'])
        ->name('vault.verify');
});
