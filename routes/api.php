<?php

declare(strict_types=1);

use App\Web\API\V1\Controllers\Admin\HrmSettingsController;
use App\Web\API\V1\Controllers\Admin\Users\CancelInvitationController;
use App\Web\API\V1\Controllers\Admin\Users\DeactivateUserController;
use App\Web\API\V1\Controllers\Admin\Users\DisableUserController;
use App\Web\API\V1\Controllers\Admin\Users\EnableUserController;
use App\Web\API\V1\Controllers\Admin\Users\InvitationController;
use App\Web\API\V1\Controllers\Admin\Users\ResendInvitationController;
use App\Web\API\V1\Controllers\Admin\Users\RestoreUserController;
use App\Web\API\V1\Controllers\Admin\Users\UserController;
use App\Web\API\V1\Controllers\Public\AcceptInvitationController;
use App\Web\API\V1\Controllers\Public\ShowInvitationController;
use App\Web\API\V1\Controllers\Auth\LoginController;
use App\Web\API\V1\Controllers\Auth\LogoutController;
use App\Web\API\V1\Controllers\Auth\MeController;
use App\Web\API\V1\Controllers\HRM\ApproveLeaveRequestController;
use App\Web\API\V1\Controllers\HRM\AttendanceController;
use App\Web\API\V1\Controllers\HRM\BranchController;
use App\Web\API\V1\Controllers\HRM\DepartmentController;
use App\Web\API\V1\Controllers\HRM\EmployeeController;
use App\Web\API\V1\Controllers\HRM\LeaveBalanceController;
use App\Web\API\V1\Controllers\HRM\LeaveRequestController;
use App\Web\API\V1\Controllers\HRM\PositionController;
use App\Web\API\V1\Controllers\HRM\RejectLeaveRequestController;
use App\Web\API\V1\Controllers\SuperAdmin\DashboardController;
use App\Web\API\V1\Controllers\SuperAdmin\TenantController;
use App\Web\API\V1\Controllers\SuperAdmin\TenantModuleController;
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
    // HRM business endpoints — gated on tenant_modules entitlement for
    // the 'hrm' module. tenant_admin can't self-rescue by re-enabling
    // via settings; admin/hrm/* (below) carries the same gate.
    // EnforceModuleEntitlement bypasses for super_admin (same pattern
    // as TenantScope/CompanyScope/ResolveTenant/ResolveCompany bypasses).
    Route::middleware('module:hrm')->prefix('hrm')->group(function (): void {
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

        // Branches — physical-location entities. Same shape as
        // Department/Position; cross-module FK to Employee follows
        // the established pattern (nullable + ON DELETE SET NULL +
        // scoped-exists guard on the FormRequest).
        Route::apiResource('branches', BranchController::class)
            ->parameters(['branches' => 'branch']);

        // Leave Balances — allocated days per (employee, leave_type,
        // period_year). remaining_days is computed on read via
        // LeaveBalanceQueryService (LEFT JOIN over approved
        // leave_requests aggregated by SUM(days_count)) — not a
        // stored column. See hrm.md "Leave Balances" for the
        // computed-state design discipline.
        Route::apiResource('leave-balances', LeaveBalanceController::class)
            ->parameters(['leave-balances' => 'leaveBalance']);
    });

    // Admin area — settings.* permissions; separate URL prefix
    // (/api/v1/admin/...) so the admin surface is grep-distinct from
    // the HRM business endpoints. Same auth+tenant+company middleware
    // chain — admin is a different sidebar, not a different security
    // boundary. The module:hrm gate is the SAME as on /hrm/* — when
    // HRM is disabled the tenant_admin loses the ability to configure
    // it (only SA can re-enable; tenant_admin can't self-rescue).
    Route::middleware('module:hrm')->prefix('admin/hrm')->group(function (): void {
        // Single-resource shape: show + update only. No collection
        // (settings is 1:1 with company); no destroy (settings always
        // exist for every company via the bootstrap listener).
        Route::get('settings', [HrmSettingsController::class, 'show'])
            ->name('admin.hrm.settings.show');
        Route::patch('settings/{settings}', [HrmSettingsController::class, 'update'])
            ->name('admin.hrm.settings.update');
    });

});

// ─────────────────────────────────────────────────────────────────────────────
// Phase 2A — User management (tenant-admin surface).
//
// Separate route group with auth:sanctum + tenant ONLY (no company
// middleware). User management is tenant-scoped — users belong to a
// tenant, NOT to a specific company within that tenant. The HRM
// settings routes above DO need company context (settings are 1:1
// with company); these don't. Lifting them out of the company group
// keeps each route's middleware chain matched to its actual
// requirements.
//
// Gating: /admin/users/* uses the users.view permission at the
// controller level via AuthorizesUserManagement::authorizeUsersAccess
// (404 for non-admin per the §10.6 feature-hide convention).
// No module entitlement gate — users management isn't an
// entitlement-gated module; every tenant has it as long as their
// tenant_admin has the perms.
// ─────────────────────────────────────────────────────────────────────────────
Route::middleware(['auth:sanctum', 'tenant'])->prefix('admin/users')->name('admin.users.')->group(function (): void {
    Route::get('/', [UserController::class, 'index'])->name('index');

    // Invitations live BEFORE the {userId} routes so the literal
    // 'invitations' segment doesn't conflict with the user-id binding.
    // Same gate (users.view at controller level) — invitations are
    // part of the user-management surface, not a separate sidebar.
    Route::prefix('invitations')->name('invitations.')->group(function (): void {
        Route::get('/', [InvitationController::class, 'index'])->name('index');
        Route::post('/', [InvitationController::class, 'store'])->name('store');
        Route::post('{invitationId}/cancel', CancelInvitationController::class)
            ->whereNumber('invitationId')->name('cancel');
        Route::post('{invitationId}/resend', ResendInvitationController::class)
            ->whereNumber('invitationId')->name('resend');
    });

    Route::get('{userId}', [UserController::class, 'show'])
        ->whereNumber('userId')->name('show');
    Route::patch('{userId}', [UserController::class, 'update'])
        ->whereNumber('userId')->name('update');

    // State-machine transitions — dedicated invokable controllers
    // per CLAUDE.md §10.2 (NOT methods on UserController).
    Route::post('{userId}/disable', DisableUserController::class)
        ->whereNumber('userId')->name('disable');
    Route::post('{userId}/enable', EnableUserController::class)
        ->whereNumber('userId')->name('enable');
    Route::post('{userId}/deactivate', DeactivateUserController::class)
        ->whereNumber('userId')->name('deactivate');
    Route::post('{userId}/restore', RestoreUserController::class)
        ->whereNumber('userId')->name('restore');
});

// ─────────────────────────────────────────────────────────────────────────────
// Public invitation endpoints — NO auth middleware. The invitee hasn't
// signed up yet; they hit these from the public AcceptInvitationPage
// (Session 5) using only the raw token from their email.
//
// SHOW returns 422 (not 404) for invalid tokens to keep the response
// shape uniform with the state-error cases (expired / cancelled /
// accepted) — the SPA branches on error_code, not status code, to
// render the matching InvitationInvalidPage variant.
//
// Token format constraint: 43 URL-safe base64 chars (Str::random(43)).
// The route constraint rejects malformed tokens before they reach the
// controller's SHA-256 hash + DB lookup.
// ─────────────────────────────────────────────────────────────────────────────
Route::prefix('invitations')->name('invitations.')->group(function (): void {
    Route::get('{token}', ShowInvitationController::class)
        ->where('token', '[A-Za-z0-9]{43}')
        ->name('show');
    Route::post('{token}/accept', AcceptInvitationController::class)
        ->where('token', '[A-Za-z0-9]{43}')
        ->name('accept');
});

// ─────────────────────────────────────────────────────────────────────────────
// Super Admin (vendor-side) endpoints — gated on user.type='super_admin'.
// No 'tenant' / 'company' middleware: SA has no tenant or company context.
// SuperAdminGuard returns 404 (not 403) for non-SA authenticated users
// per Q8 — the route effectively doesn't exist for them.
//
// Session 2 ships: tenant-modules index + sync. Session 3 adds the rest of
// the SA endpoints (tenant CRUD + dashboard).
// ─────────────────────────────────────────────────────────────────────────────
Route::middleware(['auth:sanctum', 'super_admin'])->prefix('super-admin')->group(function (): void {
    // Dashboard (Session 4). Single endpoint; 5 metrics + 2 lists per Q6.
    Route::get('dashboard', DashboardController::class)
        ->name('super-admin.dashboard');

    // Tenant CRUD (Session 3). update covers profile + status transitions
    // (suspend/resume via PATCH status). No destroy in v1 per the
    // explicit cuts (cascade complexity is a separate slice).
    Route::get('tenants', [TenantController::class, 'index'])
        ->name('super-admin.tenants.index');
    Route::post('tenants', [TenantController::class, 'store'])
        ->name('super-admin.tenants.store');
    Route::get('tenants/{tenant}', [TenantController::class, 'show'])
        ->name('super-admin.tenants.show');
    Route::patch('tenants/{tenant}', [TenantController::class, 'update'])
        ->name('super-admin.tenants.update');

    // Per-tenant module entitlement (Session 2).
    Route::get('tenants/{tenant}/modules', [TenantModuleController::class, 'index'])
        ->name('super-admin.tenants.modules.index');
    Route::patch('tenants/{tenant}/modules', [TenantModuleController::class, 'sync'])
        ->name('super-admin.tenants.modules.sync');
});
