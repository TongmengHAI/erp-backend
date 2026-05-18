<?php

declare(strict_types=1);

use App\Web\API\V1\Controllers\Auth\LoginController;
use App\Web\API\V1\Controllers\Auth\LogoutController;
use App\Web\API\V1\Controllers\Auth\MeController;
use App\Web\API\V1\Controllers\HRM\EmployeeController;
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

// --- /auth/me: tenant-scoped, company OPTIONAL ---
// auth:sanctum populates $request->user(); ResolveTenant pins TenantContext;
// ResolveCompany pins CompanyContext when resolvable, but `company:optional`
// suppresses the throw on Step 5 so a user with no resolvable company
// (e.g. a multi-company tenant whose user hasn't picked yet) still gets a
// graceful payload with current_company: null + the companies array. The
// SPA renders a picker UI instead of getting bounced.
Route::middleware(['auth:sanctum', 'tenant', 'company:optional'])->group(function (): void {
    Route::get('/auth/me', MeController::class)
        ->middleware('throttle:60,1')
        ->name('auth.me');
});

// --- Authenticated routes (tenant + company REQUIRED) ---
// Business endpoints (HRM, accounting, etc.) land in this group. The
// `company` middleware (no parameter) throws company_required when no
// company resolves — these endpoints require a chosen company to run.
Route::middleware(['auth:sanctum', 'tenant', 'company'])->group(function (): void {
    Route::prefix('hrm')->group(function (): void {
        Route::apiResource('employees', EmployeeController::class)
            ->parameters(['employees' => 'employee']);
    });
});
