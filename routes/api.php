<?php

declare(strict_types=1);

use App\Web\API\V1\Controllers\Auth\LoginController;
use App\Web\API\V1\Controllers\Auth\LogoutController;
use App\Web\API\V1\Controllers\Auth\MeController;
use App\Web\API\V1\Controllers\HRM\ApproveLeaveRequestController;
use App\Web\API\V1\Controllers\HRM\AttendanceController;
use App\Web\API\V1\Controllers\HRM\DepartmentController;
use App\Web\API\V1\Controllers\HRM\EmployeeController;
use App\Web\API\V1\Controllers\HRM\LeaveRequestController;
use App\Web\API\V1\Controllers\HRM\PositionController;
use App\Web\API\V1\Controllers\HRM\RejectLeaveRequestController;
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
        Route::apiResource('departments', DepartmentController::class)
            ->parameters(['departments' => 'department']);

        // Leave Requests — standard CRUD on the resource, plus two
        // dedicated transition endpoints. The transition endpoints are
        // NOT methods on LeaveRequestController because they have a
        // different permission (.approve = decision-making authority,
        // separate from .update which is for editing pending content),
        // a different request shape (just `note`), and a different domain
        // Action. Co-locating them would conflate three orthogonal
        // responsibilities.
        Route::apiResource('leave-requests', LeaveRequestController::class)
            ->parameters(['leave-requests' => 'leaveRequest']);

        Route::post('leave-requests/{leaveRequest}/approve', ApproveLeaveRequestController::class)
            ->name('hrm.leave-requests.approve');
        Route::post('leave-requests/{leaveRequest}/reject', RejectLeaveRequestController::class)
            ->name('hrm.leave-requests.reject');

        // Attendance — plain CRUD, no workflow endpoints. Uniqueness
        // on (employee_id, date) is enforced at the FormRequest layer
        // (named-fields 422 message) and backstopped by the partial
        // unique DB index.
        Route::apiResource('attendance', AttendanceController::class)
            ->parameters(['attendance' => 'attendance']);

        // Positions — plain CRUD, no workflow endpoints. Replaces the
        // free-text employees.job_title with a structured FK; see
        // hrm.md "Positions" section for the migration discipline.
        Route::apiResource('positions', PositionController::class)
            ->parameters(['positions' => 'position']);
    });
});
