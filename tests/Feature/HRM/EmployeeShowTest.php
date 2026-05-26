<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────────
// EmployeeShowTest — covers GET /api/v1/hrm/employees/{employee}.
//
// §7.D 5-test pattern note: 422 is N/A — the route has no body and the path
// param coerces to 404 (via route-model binding) for malformed values.
// Cross-tenant + cross-company manifest as 404 (record invisible to the
// user's scoped query), not 403.
// ─────────────────────────────────────────────────────────────────────────────

use App\Domain\HRM\Models\Department;
use App\Domain\HRM\Models\Employee;
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
        'current_tenant_id' => $this->tenant->id,
        'default_company_id' => $this->company->id,
        'current_company_id' => $this->company->id,
    ]);
    $this->admin->assignTenantRole($this->tenant, 'tenant_admin');

    $this->employee = Employee::factory()->forCompany($this->company)->create([
        'employee_code' => 'E-9999',
        'full_name' => 'Detail Subject',
    ]);
});

it('returns the full employee resource on a valid id within the current company', function (): void {
    $this->actingAs($this->admin);
    $response = $this->getJson("/api/v1/hrm/employees/{$this->employee->id}");

    $response->assertOk();
    $response->assertJsonPath('data.id', $this->employee->id);
    $response->assertJsonPath('data.employee_code', 'E-9999');
    $response->assertJsonPath('data.full_name', 'Detail Subject');
    $response->assertJsonStructure([
        'data' => [
            'id', 'employee_code', 'full_name', 'email', 'position',
            'hire_date', 'status', 'created_at', 'updated_at',
        ],
    ]);
});

it('returns 401 when called with no authenticated session', function (): void {
    $this->getJson("/api/v1/hrm/employees/{$this->employee->id}")
        ->assertStatus(401);
});

it('returns 403 when the authenticated user lacks hrm.employee.view permission', function (): void {
    $unprivileged = User::factory()->forTenant($this->tenant)->create([
        'default_company_id' => $this->company->id,
        'current_company_id' => $this->company->id,
    ]);

    $this->actingAs($unprivileged)
        ->getJson("/api/v1/hrm/employees/{$this->employee->id}")
        ->assertStatus(403);
});

it('returns 404 cross-tenant — admin in tenant A cannot view an employee in tenant B (record invisible)', function (): void {
    $otherTenant = Tenant::factory()->create();
    $otherCompany = Company::factory()->forTenant($otherTenant)->create();
    $other = Employee::factory()->forCompany($otherCompany)->create();

    $this->actingAs($this->admin)
        ->getJson("/api/v1/hrm/employees/{$other->id}")
        ->assertStatus(404);
});

it('returns 404 cross-company — admin in company X cannot view an employee in company Y of the same tenant', function (): void {
    $otherCompany = Company::factory()->forTenant($this->tenant)->create();
    $other = Employee::factory()->forCompany($otherCompany)->create();

    $this->actingAs($this->admin)
        ->getJson("/api/v1/hrm/employees/{$other->id}")
        ->assertStatus(404);
});

it('returns 200 with status reflecting EmployeeStatus enum string value', function (): void {
    $this->actingAs($this->admin);
    $response = $this->getJson("/api/v1/hrm/employees/{$this->employee->id}");

    $response->assertOk();
    expect($response->json('data.status'))->toBeIn(['active', 'on_leave', 'terminated']);
});

// ─── Department FK projection on show ─────────────────────────────────────────

it('includes nested department { id, code, name } when the employee has a department', function (): void {
    $department = Department::factory()
        ->forCompany($this->company)
        ->create(['code' => 'D-OPS', 'name' => 'Operations']);
    $this->employee->forceFill(['department_id' => $department->id])->save();

    $this->actingAs($this->admin);
    $response = $this->getJson("/api/v1/hrm/employees/{$this->employee->id}");

    $response->assertOk();
    $response->assertJsonPath('data.department.id', $department->id);
    $response->assertJsonPath('data.department.code', 'D-OPS');
    $response->assertJsonPath('data.department.name', 'Operations');
});

it('returns department: null when the employee has no department', function (): void {
    // The beforeEach employee defaults to department_id = null.
    $this->actingAs($this->admin);
    $response = $this->getJson("/api/v1/hrm/employees/{$this->employee->id}");

    $response->assertOk();
    $response->assertJsonPath('data.department', null);
});
