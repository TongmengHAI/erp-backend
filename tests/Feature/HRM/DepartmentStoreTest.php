<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────────
// DepartmentStoreTest — covers POST /api/v1/hrm/departments.
// Full §7.D 5-test pattern + cross-tenant + cross-company isolation.
// Mirrors EmployeeStoreTest.
// ─────────────────────────────────────────────────────────────────────────────

use App\Domain\HRM\Models\Department;
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

function validDepartmentPayload(array $overrides = []): array
{
    return array_merge([
        'code' => 'D-NEW1',
        'name' => 'New Department',
        'description' => 'A newly created department.',
        'status' => 'active',
    ], $overrides);
}

it('creates a department and returns 201 with the full resource', function (): void {
    $this->actingAs($this->admin);
    $response = $this->postJson('/api/v1/hrm/departments', validDepartmentPayload());

    $response->assertStatus(Response::HTTP_CREATED);
    $response->assertJsonPath('data.code', 'D-NEW1');
    $response->assertJsonPath('data.name', 'New Department');
    $response->assertJsonPath('data.status', 'active');

    $row = Department::query()->where('code', 'D-NEW1')->firstOrFail();
    expect($row->tenant_id)->toBe($this->tenant->id);
    expect($row->company_id)->toBe($this->company->id);
});

it('writes an audit row with non-null tenant_id + company_id when a department is created', function (): void {
    $this->actingAs($this->admin);
    $this->postJson('/api/v1/hrm/departments', validDepartmentPayload());

    $row = AuditLog::query()
        ->where('auditable_type', Department::class)
        ->where('action', 'created')
        ->latest('id')
        ->first();

    expect($row->tenant_id)->toBe($this->tenant->id);
    expect($row->company_id)->toBe($this->company->id);
    expect($row->actor_id)->toBe($this->admin->id);
});

it('returns 401 when called with no authenticated session', function (): void {
    $this->postJson('/api/v1/hrm/departments', validDepartmentPayload())
        ->assertStatus(401);
});

it('returns 403 when the authenticated user lacks hrm.department.create permission', function (): void {
    $viewer = User::factory()->forTenant($this->tenant)->create([
        'default_company_id' => $this->company->id,
        'current_company_id' => $this->company->id,
    ]);
    $viewer->assignTenantRole($this->tenant, 'viewer');

    $this->actingAs($viewer)
        ->postJson('/api/v1/hrm/departments', validDepartmentPayload())
        ->assertStatus(403);
});

it('returns 422 with field-keyed errors on missing required fields', function (): void {
    $this->actingAs($this->admin);
    $response = $this->postJson('/api/v1/hrm/departments', [
        // missing code, name, status
        'description' => 'partial payload',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['code', 'name', 'status']);
});

it('returns 422 when code duplicates an existing code in the same company', function (): void {
    Department::factory()->forCompany($this->company)->create(['code' => 'DUP-001']);

    $this->actingAs($this->admin);
    $this->postJson('/api/v1/hrm/departments', validDepartmentPayload(['code' => 'DUP-001']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('code');
});

it('allows the same code in different companies within the same tenant', function (): void {
    // Code uniqueness is scoped per (tenant, company), so the same code can
    // freely exist across companies. Demonstrates the scoping is correct.
    $otherCompany = Company::factory()->forTenant($this->tenant)->create();
    Department::factory()->forCompany($otherCompany)->create(['code' => 'DUP-OK']);

    $this->actingAs($this->admin);
    $this->postJson('/api/v1/hrm/departments', validDepartmentPayload(['code' => 'DUP-OK']))
        ->assertStatus(Response::HTTP_CREATED);
});

it('returns 422 when description exceeds the 500-char cap (matches the DB column and frontend Zod)', function (): void {
    $this->actingAs($this->admin);
    $this->postJson('/api/v1/hrm/departments', validDepartmentPayload([
        'description' => str_repeat('x', 501),
    ]))->assertStatus(422)->assertJsonValidationErrors('description');
});

it('isolates cross-tenant — a created department belongs to the admin tenant, never the request body', function (): void {
    $foreignTenant = Tenant::factory()->create();

    $this->actingAs($this->admin);
    $response = $this->postJson('/api/v1/hrm/departments', validDepartmentPayload([
        'tenant_id' => $foreignTenant->id, // ignored
    ]));

    $response->assertStatus(Response::HTTP_CREATED);
    $row = Department::query()->where('code', 'D-NEW1')->firstOrFail();
    expect($row->tenant_id)->toBe($this->tenant->id);
    expect($row->tenant_id)->not->toBe($foreignTenant->id);
});
