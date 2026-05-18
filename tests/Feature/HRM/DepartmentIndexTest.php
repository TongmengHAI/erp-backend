<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────────
// DepartmentIndexTest — covers GET /api/v1/hrm/departments.
//
// §7.D 5-test pattern + cross-tenant + cross-company isolation + search/filter.
// 429 (rate limit) is not asserted — covered by Laravel's own throttle tests.
// Mirrors EmployeeIndexTest verbatim.
// ─────────────────────────────────────────────────────────────────────────────

use App\Domain\HRM\Enums\DepartmentStatus;
use App\Domain\HRM\Models\Department;
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

it('returns a paginated list of departments scoped to the current tenant + company', function (): void {
    Department::factory()->forCompany($this->company)->count(3)->create();

    $this->actingAs($this->admin);
    $response = $this->getJson('/api/v1/hrm/departments');

    $response->assertOk();
    $response->assertJsonStructure([
        'data' => [['id', 'code', 'name', 'status']],
        'meta' => ['current_page', 'per_page', 'total'],
    ]);
    expect($response->json('data'))->toHaveCount(3);
    expect($response->json('meta.total'))->toBe(3);
});

it('returns 401 when called with no authenticated session', function (): void {
    $this->getJson('/api/v1/hrm/departments')->assertStatus(401);
});

it('returns 403 when the authenticated user lacks hrm.department.view permission', function (): void {
    $unprivileged = User::factory()->forTenant($this->tenant)->create([
        'default_company_id' => $this->company->id,
        'current_company_id' => $this->company->id,
    ]);
    // No role assigned — no permissions.

    $this->actingAs($unprivileged)
        ->getJson('/api/v1/hrm/departments')
        ->assertStatus(403);
});

it('returns 422 when an invalid status value is supplied as a filter', function (): void {
    $this->actingAs($this->admin)
        ->getJson('/api/v1/hrm/departments?status=not-a-real-status')
        ->assertStatus(422)
        ->assertJsonValidationErrors('status');
});

it('isolates cross-tenant — users in tenant A cannot see departments in tenant B', function (): void {
    Department::factory()->forCompany($this->company)->count(2)->create(['name' => 'Tenant A Dept']);

    $otherTenant = Tenant::factory()->create();
    $otherCompany = Company::factory()->forTenant($otherTenant)->create();
    Department::factory()->forCompany($otherCompany)->create([
        'name' => 'Tenant B Leak Marker',
    ]);

    $this->actingAs($this->admin);
    $body = $this->getJson('/api/v1/hrm/departments')->assertOk()->json();

    expect($body['meta']['total'])->toBe(2);
    expect(json_encode($body))->not->toContain('Tenant B Leak Marker');
});

it('isolates cross-company — departments in another company within the same tenant are not listed', function (): void {
    Department::factory()->forCompany($this->company)->create(['name' => 'Visible']);

    $otherCompany = Company::factory()->forTenant($this->tenant)->create();
    Department::factory()->forCompany($otherCompany)->create([
        'name' => 'Other Company Leak Marker',
    ]);

    $this->actingAs($this->admin);
    $body = $this->getJson('/api/v1/hrm/departments')->assertOk()->json();

    expect($body['meta']['total'])->toBe(1);
    expect(json_encode($body))->not->toContain('Other Company Leak Marker');
});

it('filters by status when ?status= is supplied', function (): void {
    Department::factory()->forCompany($this->company)->count(2)->create(['status' => DepartmentStatus::Active]);
    Department::factory()->forCompany($this->company)->archived()->create();

    $this->actingAs($this->admin);
    $body = $this->getJson('/api/v1/hrm/departments?status=archived')->assertOk()->json();

    expect($body['meta']['total'])->toBe(1);
    expect($body['data'][0]['status'])->toBe('archived');
});

it('filters by ?search= matching either name or code (case-insensitive)', function (): void {
    Department::factory()->forCompany($this->company)->create([
        'code' => 'D-OPS',
        'name' => 'Operations',
    ]);
    Department::factory()->forCompany($this->company)->create([
        'code' => 'D-FIN',
        'name' => 'Finance',
    ]);

    $this->actingAs($this->admin);

    // Name match (case-insensitive via ILIKE)
    expect($this->getJson('/api/v1/hrm/departments?search=operations')->json('meta.total'))->toBe(1);
    // Code match
    expect($this->getJson('/api/v1/hrm/departments?search=D-FIN')->json('meta.total'))->toBe(1);
    // No match
    expect($this->getJson('/api/v1/hrm/departments?search=nonexistent')->json('meta.total'))->toBe(0);
});
