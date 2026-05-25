<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────────
// LeaveRequestDestroyTest — covers DELETE /api/v1/hrm/leave-requests/{id}.
// §7.D pattern. Delete is soft-delete (the model uses SoftDeletes), so the
// row is invisible to subsequent reads but the DB row remains for audit.
// ─────────────────────────────────────────────────────────────────────────────

use App\Domain\HRM\Models\Employee;
use App\Domain\HRM\Models\LeaveRequest;
use App\Models\Company;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\Framework\DefaultPermissionsSeeder;
use Database\Seeders\Framework\DefaultRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withHeader('Origin', 'http://localhost');
    $this->seed([DefaultPermissionsSeeder::class, DefaultRolesSeeder::class]);

    $this->tenant = Tenant::factory()->create();
    $this->company = Company::factory()->forTenant($this->tenant)->create();
    $this->admin = User::factory()->forTenant($this->tenant)->create([
        'default_company_id' => $this->company->id,
        'current_company_id' => $this->company->id,
    ]);
    $this->admin->assignTenantRole($this->tenant, 'tenant_admin');

    $this->employee = Employee::factory()->forCompany($this->company)->create();
});

it('soft-deletes a leave_request and returns 204', function (): void {
    $request = LeaveRequest::factory()->forEmployee($this->employee)->create();

    $this->actingAs($this->admin)
        ->deleteJson("/api/v1/hrm/leave-requests/{$request->id}")
        ->assertStatus(204);

    // Row gone from default scope (SoftDeletes hides it), still in DB.
    expect(LeaveRequest::query()->find($request->id))->toBeNull();
    expect(LeaveRequest::query()->withTrashed()->find($request->id))->not->toBeNull();
});

it('returns 401 when called with no authenticated session', function (): void {
    $request = LeaveRequest::factory()->forEmployee($this->employee)->create();

    $this->deleteJson("/api/v1/hrm/leave-requests/{$request->id}")
        ->assertStatus(401);
});

it('returns 403 when the user lacks hrm.leave_request.delete permission', function (): void {
    $request = LeaveRequest::factory()->forEmployee($this->employee)->create();

    $unprivileged = User::factory()->forTenant($this->tenant)->create([
        'default_company_id' => $this->company->id,
        'current_company_id' => $this->company->id,
    ]);

    $this->actingAs($unprivileged)
        ->deleteJson("/api/v1/hrm/leave-requests/{$request->id}")
        ->assertStatus(403);
});

it('returns 404 for a cross-tenant leave_request id', function (): void {
    $otherTenant = Tenant::factory()->create();
    $otherCompany = Company::factory()->forTenant($otherTenant)->create();
    $otherEmployee = Employee::factory()->forCompany($otherCompany)->create();
    $foreignRequest = LeaveRequest::factory()->forEmployee($otherEmployee)->create();

    $this->actingAs($this->admin)
        ->deleteJson("/api/v1/hrm/leave-requests/{$foreignRequest->id}")
        ->assertStatus(404);

    // Foreign row untouched.
    expect(LeaveRequest::query()->withoutGlobalScopes()->find($foreignRequest->id)->deleted_at)
        ->toBeNull();
});

it('allows deleting an approved (decided) row — delete is the "created in error" affordance, not gated by status', function (): void {
    // .delete is NOT gated by status. Edit is read-only after a decision
    // (UpdateAction throws), but Delete continues to work for "created in
    // error" recovery. This is the deliberate asymmetry documented on
    // the Action — pin it so future regressions are visible.
    $manager = User::factory()->forTenant($this->tenant)->create();
    $request = LeaveRequest::factory()
        ->forEmployee($this->employee)
        ->approved($manager)
        ->create();

    $this->actingAs($this->admin)
        ->deleteJson("/api/v1/hrm/leave-requests/{$request->id}")
        ->assertStatus(204);
});
