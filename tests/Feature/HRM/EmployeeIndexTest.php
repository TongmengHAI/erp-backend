<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────────
// EmployeeIndexTest — covers GET /api/v1/hrm/employees.
//
// §7.D 5-test pattern + cross-tenant + cross-company isolation + search/filter.
// 429 (rate limit) is not asserted — covered by Laravel's own throttle tests.
// ─────────────────────────────────────────────────────────────────────────────

use App\Domain\HRM\Enums\EmployeeStatus;
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
        'default_company_id' => $this->company->id,
        'current_company_id' => $this->company->id,
    ]);
    $this->admin->assignTenantRole($this->tenant, 'tenant_admin');
});

it('returns a paginated list of employees scoped to the current tenant + company', function (): void {
    Employee::factory()->forCompany($this->company)->count(3)->create();

    $this->actingAs($this->admin);
    $response = $this->getJson('/api/v1/hrm/employees');

    $response->assertOk();
    $response->assertJsonStructure([
        'data' => [['id', 'employee_code', 'full_name', 'job_title', 'hire_date', 'status']],
        'meta' => ['current_page', 'per_page', 'total'],
    ]);
    expect($response->json('data'))->toHaveCount(3);
    expect($response->json('meta.total'))->toBe(3);
});

it('returns 401 when called with no authenticated session', function (): void {
    $this->getJson('/api/v1/hrm/employees')->assertStatus(401);
});

it('returns 403 when the authenticated user lacks hrm.employee.view permission', function (): void {
    $unprivileged = User::factory()->forTenant($this->tenant)->create([
        'default_company_id' => $this->company->id,
        'current_company_id' => $this->company->id,
    ]);
    // No role assigned — no permissions.

    $this->actingAs($unprivileged)
        ->getJson('/api/v1/hrm/employees')
        ->assertStatus(403);
});

it('returns 422 when an invalid status value is supplied as a filter', function (): void {
    $this->actingAs($this->admin)
        ->getJson('/api/v1/hrm/employees?status=not-a-real-status')
        ->assertStatus(422)
        ->assertJsonValidationErrors('status');
});

it('isolates cross-tenant — users in tenant A cannot see employees in tenant B', function (): void {
    Employee::factory()->forCompany($this->company)->count(2)->create(['full_name' => 'Tenant A Employee']);

    $otherTenant = Tenant::factory()->create();
    $otherCompany = Company::factory()->forTenant($otherTenant)->create();
    Employee::factory()->forCompany($otherCompany)->create([
        'full_name' => 'Tenant B Leak Marker',
    ]);

    $this->actingAs($this->admin);
    $body = $this->getJson('/api/v1/hrm/employees')->assertOk()->json();

    expect($body['meta']['total'])->toBe(2);
    expect(json_encode($body))->not->toContain('Tenant B Leak Marker');
});

it('isolates cross-company — employees in another company within the same tenant are not listed', function (): void {
    Employee::factory()->forCompany($this->company)->create(['full_name' => 'Visible']);

    $otherCompany = Company::factory()->forTenant($this->tenant)->create();
    Employee::factory()->forCompany($otherCompany)->create([
        'full_name' => 'Other Company Leak Marker',
    ]);

    $this->actingAs($this->admin);
    $body = $this->getJson('/api/v1/hrm/employees')->assertOk()->json();

    expect($body['meta']['total'])->toBe(1);
    expect(json_encode($body))->not->toContain('Other Company Leak Marker');
});

it('filters by status when ?status= is supplied', function (): void {
    Employee::factory()->forCompany($this->company)->count(2)->create(['status' => EmployeeStatus::Active]);
    Employee::factory()->forCompany($this->company)->onLeave()->create();
    Employee::factory()->forCompany($this->company)->terminated()->create();

    $this->actingAs($this->admin);
    $body = $this->getJson('/api/v1/hrm/employees?status=on_leave')->assertOk()->json();

    expect($body['meta']['total'])->toBe(1);
    expect($body['data'][0]['status'])->toBe('on_leave');
});

it('filters by ?search= matching either full_name or employee_code (case-insensitive)', function (): void {
    Employee::factory()->forCompany($this->company)->create([
        'employee_code' => 'E-AAA',
        'full_name' => 'Bopha Nuon',
    ]);
    Employee::factory()->forCompany($this->company)->create([
        'employee_code' => 'E-BBB',
        'full_name' => 'Rithy Pich',
    ]);

    $this->actingAs($this->admin);

    // Name match (case-insensitive via ILIKE)
    expect($this->getJson('/api/v1/hrm/employees?search=bopha')->json('meta.total'))->toBe(1);
    // Code match
    expect($this->getJson('/api/v1/hrm/employees?search=E-BBB')->json('meta.total'))->toBe(1);
    // No match
    expect($this->getJson('/api/v1/hrm/employees?search=nonexistent')->json('meta.total'))->toBe(0);
});

it('filters by ?department_id= and surfaces department_name in the list rows', function (): void {
    $opsDept = Department::factory()
        ->forCompany($this->company)
        ->create(['name' => 'Operations']);
    $finDept = Department::factory()
        ->forCompany($this->company)
        ->create(['name' => 'Finance']);

    $opsEmp = Employee::factory()->forCompany($this->company)->create(['full_name' => 'In Ops']);
    $opsEmp->forceFill(['department_id' => $opsDept->id])->save();
    $finEmp = Employee::factory()->forCompany($this->company)->create(['full_name' => 'In Fin']);
    $finEmp->forceFill(['department_id' => $finDept->id])->save();
    Employee::factory()->forCompany($this->company)->create(['full_name' => 'Unassigned']);

    $this->actingAs($this->admin);

    // Filter narrows to one row.
    $body = $this->getJson("/api/v1/hrm/employees?department_id={$opsDept->id}")
        ->assertOk()->json();
    expect($body['meta']['total'])->toBe(1);
    expect($body['data'][0]['full_name'])->toBe('In Ops');
    // List shape carries department_name (not the nested object).
    expect($body['data'][0]['department_name'])->toBe('Operations');

    // Cross-company department id silently returns empty (no 422; the scope
    // simply doesn't match).
    $otherCompany = Company::factory()->forTenant($this->tenant)->create();
    $foreignDept = Department::factory()
        ->forCompany($otherCompany)
        ->create();
    expect($this->getJson("/api/v1/hrm/employees?department_id={$foreignDept->id}")
        ->json('meta.total'))->toBe(0);
});

it('list rows carry department_name=null for employees with no department', function (): void {
    Employee::factory()->forCompany($this->company)->create(['full_name' => 'Solo']);

    $this->actingAs($this->admin);
    $body = $this->getJson('/api/v1/hrm/employees')->assertOk()->json();

    expect($body['data'][0]['department_name'])->toBeNull();
});
