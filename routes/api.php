<?php

use App\Http\Controllers\Api\V1\AssetController;
use App\Http\Controllers\Api\V1\CampaignController;
use App\Http\Controllers\Api\V1\BillboardController;
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
|   • Billboards → Sanctum token with 'billboard:*' abilities
|   • Admin dashboard  → Sanctum token with no specific ability restriction
|                        (add admin user auth + abilities if multi-tenant)
|   • Public vault     → no auth; rate-limited
|
*/

Route::prefix('v1')->group(function () {

    // ── Billboard Sync Endpoints ─────────────────────────────────────────────────
    // Authenticated with long-lived Sanctum tokens provisioned per physical board.

    Route::middleware(['auth:sanctum', 'billboard.token:billboard:sync'])
        ->get('/sync', [SyncController::class, 'sync'])
        ->name('sync.index');

    // Cheap reachability probe for the billboard's active connectivity check.
    // Also returns server_time so the billboard can bound clock skew on played_at.
    Route::middleware(['auth:sanctum', 'billboard.token:billboard:sync'])
        ->get('/sync/ping', [SyncController::class, 'ping'])
        ->name('sync.ping');

    Route::middleware(['auth:sanctum', 'billboard.token:billboard:log'])
        ->post('/logs', [SyncController::class, 'storeLogs'])
        ->name('sync.logs');

    Route::middleware(['auth:sanctum', 'billboard.token:billboard:sync'])
        ->get('/assets/{assetId}/download', [SyncController::class, 'assetDownload'])
        ->name('sync.asset-download');

    Route::middleware(['auth:sanctum', 'billboard.token:billboard:sync'])
        ->get('/assets/{assetId}/serve', [SyncController::class, 'serveAsset'])
        ->name('sync.asset-serve');

    Route::middleware(['auth:sanctum', 'billboard.token:billboard:sync'])
        ->post('/playback/start', [SyncController::class, 'reportPlaybackStart'])
        ->name('sync.playback-start');


    // ── Authentication Routes ─────────────────────────────────────────────────
    Route::post('/login', [App\Http\Controllers\Api\V1\AuthController::class, 'login'])->name('login');

    // Billboard player exchanges its unique password for a billboard token.
    Route::middleware('throttle:10,1')
        ->post('/billboard/login', [BillboardController::class, 'login'])
        ->name('billboard.login');

    // ── Admin Routes ──────────────────────────────────────────────────────────
    // Protected by Sanctum and Admin Token Middleware.

    Route::middleware(['auth:sanctum', 'admin.token'])->prefix('admin')->name('admin.')->group(function () {

        // Billboards (board provisioning)
        Route::apiResource('campaigns', CampaignController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::apiResource('billboards', BillboardController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::get('billboards/{billboard}/fallback-spots', [FallbackSpotController::class, 'index'])->name('billboards.fallback-spots.index');
        Route::get('billboards/{billboard}/schedule', [BillboardController::class, 'schedule'])->name('billboards.schedule');
        Route::put('billboards/{billboard}/loop-order', [BillboardController::class, 'updateLoopOrder'])->name('billboards.loop-order');
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
        Route::delete('overrides', [OverrideController::class, 'destroy'])->name('overrides.destroy');

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
