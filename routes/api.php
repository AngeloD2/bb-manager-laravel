<?php

use App\Http\Controllers\Api\V1\AssetController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\FolderController;
use App\Http\Controllers\Api\V1\OverrideController;
use App\Http\Controllers\Api\V1\SyncController;
use App\Http\Controllers\Api\V1\VaultController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| BCC API Routes  (prefix: /api/v1)
|--------------------------------------------------------------------------
|
| Three guard layers:
|   • Billboard devices → Sanctum token with 'device:*' abilities
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

    // ── Authentication Route ──────────────────────────────────────────────────
    Route::post('/login', [App\Http\Controllers\Api\V1\AuthController::class, 'login'])->name('login');

    // ── Admin Routes ──────────────────────────────────────────────────────────
    // Protected by Sanctum and Admin Token Middleware.

    Route::middleware(['auth:sanctum', 'admin.token'])->prefix('admin')->name('admin.')->group(function () {

        // Devices (board provisioning)
        Route::apiResource('devices', DeviceController::class)->only(['index', 'store', 'destroy']);

        // Folders
        Route::apiResource('folders', FolderController::class)->only(['index', 'store', 'update', 'destroy']);

        // Assets
        Route::apiResource('assets', AssetController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::post('assets/presigned-url',          [AssetController::class, 'presignedUrl'])->name('assets.presigned-url');
        Route::post('assets/multipart-upload',       [AssetController::class, 'multipartUpload'])->name('assets.multipart-upload');
        Route::post('assets/{asset}/confirm',         [AssetController::class, 'confirmUpload'])->name('assets.confirm');

        // Override Protocol (Play Next)
        Route::post('overrides', [OverrideController::class, 'store'])->name('overrides.store');

        // Secure Vault links (admin management)
        Route::get('vault/links',         [VaultController::class, 'index'])->name('vault.links.index');
        Route::post('vault/links',        [VaultController::class, 'store'])->name('vault.links.store');
        Route::delete('vault/links/{link}', [VaultController::class, 'destroy'])->name('vault.links.destroy');
    });

    // ── Public Vault Verification ─────────────────────────────────────────────
    // Rate-limited to prevent PIN brute-forcing.

    Route::middleware('throttle:10,1')   // 10 attempts per minute per IP
        ->post('/vault/verify', [VaultController::class, 'verify'])
        ->name('vault.verify');
});
