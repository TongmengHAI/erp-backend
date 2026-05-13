<?php

declare(strict_types=1);

use App\Web\API\V1\Controllers\Auth\LoginController;
use App\Web\API\V1\Controllers\Auth\LogoutController;
use App\Web\API\V1\Controllers\Auth\MeController;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => [
    'status' => 'ok',
    'time' => now()->toIso8601String(),
]);

// --- Auth (public; no tenant context required) ---
Route::post('/auth/login', LoginController::class)
    ->middleware('throttle:login')
    ->name('auth.login');

// --- Authenticated routes WITHOUT tenant requirement ---
// Logout must remain reachable even when the user's tenant is suspended;
// the `tenant` middleware would otherwise return 401 tenant_inactive and
// trap them with no way out.
Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/auth/logout', LogoutController::class)
        ->middleware('throttle:30,1')
        ->name('auth.logout');
});

// --- Authenticated routes (tenant-scoped) ---
// auth:sanctum populates $request->user(); ResolveTenant pins TenantContext
// and Spatie's PermissionRegistrar team_id to the user's current tenant.
// Future business endpoints (HRM, accounting, etc.) live in this group.
Route::middleware(['auth:sanctum', 'tenant'])->group(function (): void {
    Route::get('/auth/me', MeController::class)
        ->middleware('throttle:60,1')
        ->name('auth.me');
});
