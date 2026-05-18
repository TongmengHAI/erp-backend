<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────────
// EmployeeStoreTest — covers POST /api/v1/hrm/employees.
// Full §7.D 5-test pattern + cross-tenant + cross-company isolation.
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
});

function validEmployeePayload(array $overrides = []): array
{
    return array_merge([
        'employee_code' => 'E-NEW1',
        'full_name' => 'New Hire',
        'email' => 'new@example.test',
        'job_title' => 'Specialist',
        'hire_date' => '2026-01-15',
        'status' => 'active',
    ], $overrides);
}

it('creates an employee and returns 201 with the full resource', function (): void {
    $this->actingAs($this->admin);
    $response = $this->postJson('/api/v1/hrm/employees', validEmployeePayload());

    $response->assertStatus(Response::HTTP_CREATED);
    $response->assertJsonPath('data.employee_code', 'E-NEW1');
    $response->assertJsonPath('data.full_name', 'New Hire');
    $response->assertJsonPath('data.status', 'active');

    $row = Employee::query()->where('employee_code', 'E-NEW1')->firstOrFail();
    expect($row->tenant_id)->toBe($this->tenant->id);
    expect($row->company_id)->toBe($this->company->id);
});

it('writes an audit row with non-null tenant_id + company_id when an employee is created', function (): void {
    $this->actingAs($this->admin);
    $this->postJson('/api/v1/hrm/employees', validEmployeePayload());

    $row = AuditLog::query()
        ->where('auditable_type', Employee::class)
        ->where('action', 'created')
        ->latest('id')
        ->first();

    expect($row->tenant_id)->toBe($this->tenant->id);
    expect($row->company_id)->toBe($this->company->id);
    expect($row->actor_id)->toBe($this->admin->id);
});

it('returns 401 when called with no authenticated session', function (): void {
    $this->postJson('/api/v1/hrm/employees', validEmployeePayload())
        ->assertStatus(401);
});

it('returns 403 when the authenticated user lacks hrm.employee.create permission', function (): void {
    $viewer = User::factory()->forTenant($this->tenant)->create([
        'default_company_id' => $this->company->id,
        'current_company_id' => $this->company->id,
    ]);
    $viewer->assignTenantRole($this->tenant, 'viewer');

    $this->actingAs($viewer)
        ->postJson('/api/v1/hrm/employees', validEmployeePayload())
        ->assertStatus(403);
});

it('returns 422 with field-keyed errors on missing required fields', function (): void {
    $this->actingAs($this->admin);
    $response = $this->postJson('/api/v1/hrm/employees', [
        // missing employee_code, full_name, hire_date, status
        'email' => 'partial@example.test',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['employee_code', 'full_name', 'hire_date', 'status']);
});

it('returns 422 when employee_code duplicates an existing code in the same company', function (): void {
    Employee::factory()->forCompany($this->company)->create(['employee_code' => 'DUP-001']);

    $this->actingAs($this->admin);
    $this->postJson('/api/v1/hrm/employees', validEmployeePayload(['employee_code' => 'DUP-001']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('employee_code');
});

it('allows the same employee_code in different companies within the same tenant', function (): void {
    // Code uniqueness is scoped per (tenant, company), so the same code can
    // freely exist across companies. Demonstrates the scoping is correct.
    $otherCompany = Company::factory()->forTenant($this->tenant)->create();
    Employee::factory()->forCompany($otherCompany)->create(['employee_code' => 'DUP-OK']);

    $this->actingAs($this->admin);
    $this->postJson('/api/v1/hrm/employees', validEmployeePayload(['employee_code' => 'DUP-OK']))
        ->assertStatus(Response::HTTP_CREATED);
});

it('isolates cross-tenant — a created employee belongs to the admin tenant, never the request body', function (): void {
    // Even if a malicious client could inject tenant_id into the body, the
    // request validation doesn't accept it; the trait auto-fills from context.
    $foreignTenant = Tenant::factory()->create();

    $this->actingAs($this->admin);
    $response = $this->postJson('/api/v1/hrm/employees', validEmployeePayload([
        'tenant_id' => $foreignTenant->id, // ignored
    ]));

    $response->assertStatus(Response::HTTP_CREATED);
    $row = Employee::query()->where('employee_code', 'E-NEW1')->firstOrFail();
    expect($row->tenant_id)->toBe($this->tenant->id);
    expect($row->tenant_id)->not->toBe($foreignTenant->id);
});
