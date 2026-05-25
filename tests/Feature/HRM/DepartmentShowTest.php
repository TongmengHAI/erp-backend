<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────────
// DepartmentShowTest — covers GET /api/v1/hrm/departments/{department}.
//
// §7.D 5-test pattern note: 422 is N/A — the route has no body and the path
// param coerces to 404 (via route-model binding) for malformed values.
// Cross-tenant + cross-company manifest as 404 (record invisible to the
// user's scoped query), not 403. Mirrors EmployeeShowTest.
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

    $this->department = Department::factory()->forCompany($this->company)->create([
        'code' => 'D-OPS',
        'name' => 'Operations',
        'description' => 'Operations team.',
    ]);
});

it('returns the full department resource on a valid id within the current company', function (): void {
    $this->actingAs($this->admin);
    $response = $this->getJson("/api/v1/hrm/departments/{$this->department->id}");

    $response->assertOk();
    $response->assertJsonPath('data.id', $this->department->id);
    $response->assertJsonPath('data.code', 'D-OPS');
    $response->assertJsonPath('data.name', 'Operations');
    $response->assertJsonPath('data.description', 'Operations team.');
    $response->assertJsonStructure([
        'data' => [
            'id', 'code', 'name', 'description', 'status', 'created_at', 'updated_at',
        ],
    ]);
});

it('returns 401 when called with no authenticated session', function (): void {
    $this->getJson("/api/v1/hrm/departments/{$this->department->id}")
        ->assertStatus(401);
});

it('returns 403 when the authenticated user lacks hrm.department.view permission', function (): void {
    $unprivileged = User::factory()->forTenant($this->tenant)->create([
        'default_company_id' => $this->company->id,
        'current_company_id' => $this->company->id,
    ]);

    $this->actingAs($unprivileged)
        ->getJson("/api/v1/hrm/departments/{$this->department->id}")
        ->assertStatus(403);
});

it('returns 404 cross-tenant — admin in tenant A cannot view a department in tenant B (record invisible)', function (): void {
    $otherTenant = Tenant::factory()->create();
    $otherCompany = Company::factory()->forTenant($otherTenant)->create();
    $other = Department::factory()->forCompany($otherCompany)->create();

    $this->actingAs($this->admin)
        ->getJson("/api/v1/hrm/departments/{$other->id}")
        ->assertStatus(404);
});

it('returns 404 cross-company — admin in company X cannot view a department in company Y of the same tenant', function (): void {
    $otherCompany = Company::factory()->forTenant($this->tenant)->create();
    $other = Department::factory()->forCompany($otherCompany)->create();

    $this->actingAs($this->admin)
        ->getJson("/api/v1/hrm/departments/{$other->id}")
        ->assertStatus(404);
});

it('returns 200 with status reflecting DepartmentStatus enum string value', function (): void {
    $this->actingAs($this->admin);
    $response = $this->getJson("/api/v1/hrm/departments/{$this->department->id}");

    $response->assertOk();
    expect($response->json('data.status'))->toBeIn(['active', 'archived']);
});

it('includes employees_count matching the actual number of employees in this department', function (): void {
    // Three same-company employees attached to this department, two
    // unattached, plus one in a different department of the same company.
    // employees_count for $this->department must be exactly 3.
    Employee::factory()
        ->forCompany($this->company)
        ->count(3)
        ->create(['department_id' => $this->department->id]);
    Employee::factory()
        ->forCompany($this->company)
        ->count(2)
        ->create(['department_id' => null]);
    $otherDept = Department::factory()
        ->forCompany($this->company)
        ->create();
    Employee::factory()
        ->forCompany($this->company)
        ->create(['department_id' => $otherDept->id]);

    $this->actingAs($this->admin);
    $response = $this->getJson("/api/v1/hrm/departments/{$this->department->id}");

    $response->assertOk();
    $response->assertJsonPath('data.employees_count', 3);
});
