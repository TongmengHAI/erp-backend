<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────────
// EmployeeDestroyTest — covers DELETE /api/v1/hrm/employees/{employee}.
//
// §7.D 5-test pattern note: 422 is N/A — DELETE accepts no body. Idempotency
// IS asserted (second delete on a soft-deleted record returns 404 since the
// global scope hides it).
// ─────────────────────────────────────────────────────────────────────────────

use App\Domain\HRM\Models\Employee;
use App\Models\Company;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Audit\Models\AuditLog;
use Database\Seeders\Framework\DefaultPermissionsSeeder;
use Database\Seeders\Framework\DefaultRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpFoundation\Response;

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

it('soft-deletes the employee and returns 204', function (): void {
    $this->actingAs($this->admin);
    $response = $this->deleteJson("/api/v1/hrm/employees/{$this->employee->id}");

    $response->assertStatus(Response::HTTP_NO_CONTENT);
    expect(Employee::query()->find($this->employee->id))->toBeNull();
    // Visible only via withTrashed — proves SoftDeletes is the mechanism.
    expect(Employee::withTrashed()->find($this->employee->id)->deleted_at)->not->toBeNull();
});

it('writes a soft_deleted audit row with the employee company_id captured', function (): void {
    $this->actingAs($this->admin);
    $this->deleteJson("/api/v1/hrm/employees/{$this->employee->id}");

    $row = AuditLog::query()
        ->where('auditable_type', Employee::class)
        ->where('auditable_id', $this->employee->id)
        ->where('action', 'soft_deleted')
        ->first();

    expect($row)->not->toBeNull();
    expect($row->tenant_id)->toBe($this->tenant->id);
    expect($row->company_id)->toBe($this->company->id);
});

it('returns 401 when called with no authenticated session', function (): void {
    $this->deleteJson("/api/v1/hrm/employees/{$this->employee->id}")
        ->assertStatus(401);
});

it('returns 403 when the authenticated user lacks hrm.employee.delete permission', function (): void {
    $viewer = User::factory()->forTenant($this->tenant)->create([
        'default_company_id' => $this->company->id,
        'current_company_id' => $this->company->id,
    ]);
    $viewer->assignTenantRole($this->tenant, 'viewer');

    $this->actingAs($viewer)
        ->deleteJson("/api/v1/hrm/employees/{$this->employee->id}")
        ->assertStatus(403);
});

it('returns 404 on a second delete (idempotency: soft-deleted records are invisible)', function (): void {
    $this->actingAs($this->admin);
    $this->deleteJson("/api/v1/hrm/employees/{$this->employee->id}")
        ->assertStatus(Response::HTTP_NO_CONTENT);
    $this->deleteJson("/api/v1/hrm/employees/{$this->employee->id}")
        ->assertStatus(404);
});

it('returns 404 cross-tenant — admin cannot delete an employee in another tenant', function (): void {
    $otherTenant = Tenant::factory()->create();
    $otherCompany = Company::factory()->forTenant($otherTenant)->create();
    $other = Employee::factory()->forCompany($otherCompany)->create();

    $this->actingAs($this->admin)
        ->deleteJson("/api/v1/hrm/employees/{$other->id}")
        ->assertStatus(404);
});

it('returns 404 cross-company — admin in company X cannot delete an employee in company Y', function (): void {
    $otherCompany = Company::factory()->forTenant($this->tenant)->create();
    $other = Employee::factory()->forCompany($otherCompany)->create();

    $this->actingAs($this->admin)
        ->deleteJson("/api/v1/hrm/employees/{$other->id}")
        ->assertStatus(404);
});
