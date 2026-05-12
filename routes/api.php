<?php

declare(strict_types=1);

use App\Web\API\V1\Controllers\Auth\LoginController;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => [
    'status' => 'ok',
    'time' => now()->toIso8601String(),
]);

// --- Auth (public; no tenant context required) ---
Route::post('/auth/login', LoginController::class)
    ->middleware('throttle:login')
    ->name('auth.login');

// --- Authenticated routes (tenant-scoped) ---
// Slices 5–7 will add /auth/me, /auth/switch, /auth/logout here.
// Future business endpoints (HRM, accounting, etc.) live in this group too.
Route::middleware(['auth:sanctum', 'tenant'])->group(function (): void {
    //
});
