<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────────
// DepartmentUpdateTest — covers PATCH /api/v1/hrm/departments/{department}.
// Full §7.D 5-test pattern + cross-tenant + cross-company isolation.
// Mirrors EmployeeUpdateTest.
// ─────────────────────────────────────────────────────────────────────────────

use App\Domain\HRM\Models\Department;
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

    $this->department = Department::factory()->forCompany($this->company)->create([
        'code' => 'D-EDIT',
        'name' => 'Original Name',
        'description' => 'Original description.',
    ]);
});

it('updates the department and returns the refreshed resource', function (): void {
    $this->actingAs($this->admin);
    $response = $this->patchJson("/api/v1/hrm/departments/{$this->department->id}", [
        'name' => 'Updated Name',
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.name', 'Updated Name');
    // Unchanged fields stay intact.
    $response->assertJsonPath('data.description', 'Original description.');
    expect($this->department->fresh()->name)->toBe('Updated Name');
});

it('writes a diff-only audit row capturing only the changed fields', function (): void {
    $this->actingAs($this->admin);
    $this->patchJson("/api/v1/hrm/departments/{$this->department->id}", [
        'status' => 'archived',
    ])->assertOk();

    $row = AuditLog::query()
        ->where('auditable_type', Department::class)
        ->where('auditable_id', $this->department->id)
        ->where('action', 'updated')
        ->latest('id')
        ->first();

    expect($row->before)->toEqual(['status' => 'active']);
    expect($row->after)->toEqual(['status' => 'archived']);
    expect($row->company_id)->toBe($this->company->id);
});

it('returns 401 when called with no authenticated session', function (): void {
    $this->patchJson("/api/v1/hrm/departments/{$this->department->id}", ['name' => 'X'])
        ->assertStatus(401);
});

it('returns 403 when the authenticated user lacks hrm.department.update permission', function (): void {
    $viewer = User::factory()->forTenant($this->tenant)->create([
        'default_company_id' => $this->company->id,
        'current_company_id' => $this->company->id,
    ]);
    $viewer->assignTenantRole($this->tenant, 'viewer');

    $this->actingAs($viewer)
        ->patchJson("/api/v1/hrm/departments/{$this->department->id}", ['name' => 'X'])
        ->assertStatus(403);
});

it('returns 422 when the supplied status is not in the DepartmentStatus enum', function (): void {
    $this->actingAs($this->admin);
    $this->patchJson("/api/v1/hrm/departments/{$this->department->id}", ['status' => 'gibberish'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('status');
});

it('returns 422 when changing code to one already in use within the same company', function (): void {
    Department::factory()->forCompany($this->company)->create(['code' => 'D-TAKEN']);

    $this->actingAs($this->admin);
    $this->patchJson("/api/v1/hrm/departments/{$this->department->id}", ['code' => 'D-TAKEN'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('code');
});

it('allows keeping the same code on update (ignore-self in unique check)', function (): void {
    $this->actingAs($this->admin);
    $this->patchJson("/api/v1/hrm/departments/{$this->department->id}", [
        'code' => 'D-EDIT', // same as current
        'name' => 'Same Code OK',
    ])->assertOk();
});

it('returns 422 when description exceeds the 500-char cap', function (): void {
    $this->actingAs($this->admin);
    $this->patchJson("/api/v1/hrm/departments/{$this->department->id}", [
        'description' => str_repeat('x', 501),
    ])->assertStatus(422)->assertJsonValidationErrors('description');
});

it('returns 404 cross-tenant — admin cannot update a department in another tenant', function (): void {
    $otherTenant = Tenant::factory()->create();
    $otherCompany = Company::factory()->forTenant($otherTenant)->create();
    $other = Department::factory()->forCompany($otherCompany)->create();

    $this->actingAs($this->admin)
        ->patchJson("/api/v1/hrm/departments/{$other->id}", ['name' => 'hijack'])
        ->assertStatus(404);
});

it('returns 404 cross-company — admin in company X cannot update a department in company Y', function (): void {
    $otherCompany = Company::factory()->forTenant($this->tenant)->create();
    $other = Department::factory()->forCompany($otherCompany)->create();

    $this->actingAs($this->admin)
        ->patchJson("/api/v1/hrm/departments/{$other->id}", ['name' => 'hijack'])
        ->assertStatus(404);
});
