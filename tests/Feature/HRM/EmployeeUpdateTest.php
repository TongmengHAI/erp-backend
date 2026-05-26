<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────────
// EmployeeUpdateTest — covers PATCH /api/v1/hrm/employees/{employee}.
// Full §7.D 5-test pattern + cross-tenant + cross-company isolation.
// ─────────────────────────────────────────────────────────────────────────────

use App\Domain\HRM\Models\Department;
use App\Domain\HRM\Models\Employee;
use App\Domain\HRM\Models\Position;
use App\Models\Company;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Audit\Models\AuditLog;
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

    $this->employee = Employee::factory()->forCompany($this->company)->create([
        'employee_code' => 'E-EDIT',
        'full_name' => 'Original Name',
    ]);
});

it('updates the employee and returns the refreshed resource', function (): void {
    $this->actingAs($this->admin);
    $response = $this->patchJson("/api/v1/hrm/employees/{$this->employee->id}", [
        'full_name' => 'Updated Name',
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.full_name', 'Updated Name');
    // Unchanged fields stay intact.
    $response->assertJsonPath('data.employee_code', 'E-EDIT');
    expect($this->employee->fresh()->full_name)->toBe('Updated Name');
});

it('writes a diff-only audit row capturing only the changed fields', function (): void {
    $this->actingAs($this->admin);
    $this->patchJson("/api/v1/hrm/employees/{$this->employee->id}", [
        'status' => 'on_leave',
    ])->assertOk();

    $row = AuditLog::query()
        ->where('auditable_type', Employee::class)
        ->where('auditable_id', $this->employee->id)
        ->where('action', 'updated')
        ->latest('id')
        ->first();

    expect($row->before)->toEqual(['status' => 'active']);
    expect($row->after)->toEqual(['status' => 'on_leave']);
    expect($row->company_id)->toBe($this->company->id);
});

it('returns 401 when called with no authenticated session', function (): void {
    $this->patchJson("/api/v1/hrm/employees/{$this->employee->id}", ['full_name' => 'X'])
        ->assertStatus(401);
});

it('returns 403 when the authenticated user lacks hrm.employee.update permission', function (): void {
    $viewer = User::factory()->forTenant($this->tenant)->create([
        'default_company_id' => $this->company->id,
        'current_company_id' => $this->company->id,
    ]);
    $viewer->assignTenantRole($this->tenant, 'viewer');

    $this->actingAs($viewer)
        ->patchJson("/api/v1/hrm/employees/{$this->employee->id}", ['full_name' => 'X'])
        ->assertStatus(403);
});

it('returns 422 when the supplied status is not in the EmployeeStatus enum', function (): void {
    $this->actingAs($this->admin);
    $this->patchJson("/api/v1/hrm/employees/{$this->employee->id}", ['status' => 'gibberish'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('status');
});

it('returns 422 when changing employee_code to one already in use within the same company', function (): void {
    Employee::factory()->forCompany($this->company)->create(['employee_code' => 'E-TAKEN']);

    $this->actingAs($this->admin);
    $this->patchJson("/api/v1/hrm/employees/{$this->employee->id}", ['employee_code' => 'E-TAKEN'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('employee_code');
});

it('allows keeping the same employee_code on update (ignore-self in unique check)', function (): void {
    $this->actingAs($this->admin);
    $this->patchJson("/api/v1/hrm/employees/{$this->employee->id}", [
        'employee_code' => 'E-EDIT', // same as current
        'full_name' => 'Same Code OK',
    ])->assertOk();
});

it('returns 404 cross-tenant — admin cannot update an employee in another tenant', function (): void {
    $otherTenant = Tenant::factory()->create();
    $otherCompany = Company::factory()->forTenant($otherTenant)->create();
    $other = Employee::factory()->forCompany($otherCompany)->create();

    $this->actingAs($this->admin)
        ->patchJson("/api/v1/hrm/employees/{$other->id}", ['full_name' => 'hijack'])
        ->assertStatus(404);
});

it('returns 404 cross-company — admin in company X cannot update an employee in company Y', function (): void {
    $otherCompany = Company::factory()->forTenant($this->tenant)->create();
    $other = Employee::factory()->forCompany($otherCompany)->create();

    $this->actingAs($this->admin)
        ->patchJson("/api/v1/hrm/employees/{$other->id}", ['full_name' => 'hijack'])
        ->assertStatus(404);
});

// ─── Department FK update scenarios ──────────────────────────────────────────

it('PATCH department_id to a valid same-company department persists the FK', function (): void {
    $department = Department::factory()
        ->forCompany($this->company)
        ->create();

    $this->actingAs($this->admin);
    $response = $this->patchJson("/api/v1/hrm/employees/{$this->employee->id}", [
        'department_id' => $department->id,
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.department.id', $department->id);
    expect($this->employee->fresh()->department_id)->toBe($department->id);
});

it('PATCH department_id to null clears the department', function (): void {
    // First attach a department.
    $department = Department::factory()
        ->forCompany($this->company)
        ->create();
    $this->employee->forceFill(['department_id' => $department->id])->save();

    $this->actingAs($this->admin);
    $response = $this->patchJson("/api/v1/hrm/employees/{$this->employee->id}", [
        'department_id' => null,
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.department', null);
    expect($this->employee->fresh()->department_id)->toBeNull();
});

it('PATCH rejects 422 when department_id points at a foreign-company department', function (): void {
    $otherCompany = Company::factory()->forTenant($this->tenant)->create();
    $foreignDepartment = Department::factory()
        ->forCompany($otherCompany)
        ->create();

    $this->actingAs($this->admin);
    $this->patchJson("/api/v1/hrm/employees/{$this->employee->id}", [
        'department_id' => $foreignDepartment->id,
    ])->assertStatus(422)->assertJsonValidationErrors('department_id');

    // Unchanged.
    expect($this->employee->fresh()->department_id)->toBeNull();
});

// ─── Position FK update scenarios (Positions slice cutover) ──────────────────

it('PATCH position_id to a valid same-company position persists the FK', function (): void {
    $position = Position::factory()
        ->forCompany($this->company)
        ->create(['code' => 'P-MGR', 'title' => 'Manager']);

    $this->actingAs($this->admin);
    $response = $this->patchJson("/api/v1/hrm/employees/{$this->employee->id}", [
        'position_id' => $position->id,
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.position.id', $position->id);
    $response->assertJsonPath('data.position.title', 'Manager');
    expect($this->employee->fresh()->position_id)->toBe($position->id);
});

it('PATCH position_id to null clears the position', function (): void {
    $position = Position::factory()
        ->forCompany($this->company)
        ->create();
    $this->employee->forceFill(['position_id' => $position->id])->save();

    $this->actingAs($this->admin);
    $response = $this->patchJson("/api/v1/hrm/employees/{$this->employee->id}", [
        'position_id' => null,
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.position', null);
    expect($this->employee->fresh()->position_id)->toBeNull();
});

it('LOAD-BEARING: PATCH rejects 422 when position_id points at a foreign-company position', function (): void {
    // Same load-bearing scoped-FK isolation guard as department_id.
    // Without the Rule::exists where() clause on the FormRequest, this
    // would persist a cross-company position id and silently leak.
    $otherCompany = Company::factory()->forTenant($this->tenant)->create();
    $foreignPosition = Position::factory()
        ->forCompany($otherCompany)
        ->create();

    $this->actingAs($this->admin);
    $this->patchJson("/api/v1/hrm/employees/{$this->employee->id}", [
        'position_id' => $foreignPosition->id,
    ])->assertStatus(422)->assertJsonValidationErrors('position_id');

    expect($this->employee->fresh()->position_id)->toBeNull();
});
