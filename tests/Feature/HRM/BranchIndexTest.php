<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────────
// BranchIndexTest — covers GET /api/v1/hrm/branches.
// Mirror of PositionIndexTest. Standard 5-test pattern + cross-tenant +
// cross-company isolation + search/filter. Plus a city-search case
// because the search clause includes city (not present on Position).
// ─────────────────────────────────────────────────────────────────────────────

use App\Domain\HRM\Enums\BranchStatus;
use App\Domain\HRM\Models\Branch;
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

it('returns a paginated list of branches scoped to the current tenant + company', function (): void {
    Branch::factory()->forCompany($this->company)->count(3)->create();

    $this->actingAs($this->admin);
    $response = $this->getJson('/api/v1/hrm/branches');

    $response->assertOk();
    $response->assertJsonStructure([
        'data' => [['id', 'code', 'name', 'city', 'status']],
        'meta' => ['current_page', 'per_page', 'total'],
    ]);
    expect($response->json('meta.total'))->toBe(3);
});

it('returns 401 when called with no authenticated session', function (): void {
    $this->getJson('/api/v1/hrm/branches')->assertStatus(401);
});

it('returns 403 when the authenticated user lacks hrm.branch.view permission', function (): void {
    $unprivileged = User::factory()->forTenant($this->tenant)->create([
        'default_company_id' => $this->company->id,
        'current_company_id' => $this->company->id,
    ]);

    $this->actingAs($unprivileged)
        ->getJson('/api/v1/hrm/branches')
        ->assertStatus(403);
});

it('returns 422 when an invalid status value is supplied as a filter', function (): void {
    $this->actingAs($this->admin)
        ->getJson('/api/v1/hrm/branches?status=not-a-real-status')
        ->assertStatus(422)
        ->assertJsonValidationErrors('status');
});

it('isolates cross-tenant — users in tenant A cannot see branches in tenant B', function (): void {
    Branch::factory()->forCompany($this->company)->count(2)->create(['name' => 'Tenant A Branch']);

    $otherTenant = Tenant::factory()->create();
    $otherCompany = Company::factory()->forTenant($otherTenant)->create();
    Branch::factory()->forCompany($otherCompany)->create([
        'name' => 'Tenant B Leak Marker',
    ]);

    $this->actingAs($this->admin);
    $body = $this->getJson('/api/v1/hrm/branches')->assertOk()->json();

    expect($body['meta']['total'])->toBe(2);
    expect(json_encode($body))->not->toContain('Tenant B Leak Marker');
});

it('isolates cross-company — branches in another company within the same tenant are not listed', function (): void {
    Branch::factory()->forCompany($this->company)->create(['name' => 'Visible']);

    $otherCompany = Company::factory()->forTenant($this->tenant)->create();
    Branch::factory()->forCompany($otherCompany)->create([
        'name' => 'Other Company Leak Marker',
    ]);

    $this->actingAs($this->admin);
    $body = $this->getJson('/api/v1/hrm/branches')->assertOk()->json();

    expect($body['meta']['total'])->toBe(1);
    expect(json_encode($body))->not->toContain('Other Company Leak Marker');
});

it('filters by status when ?status= is supplied', function (): void {
    Branch::factory()->forCompany($this->company)->count(2)->create(['status' => BranchStatus::Active]);
    Branch::factory()->forCompany($this->company)->archived()->create();

    $this->actingAs($this->admin);
    $body = $this->getJson('/api/v1/hrm/branches?status=archived')->assertOk()->json();

    expect($body['meta']['total'])->toBe(1);
    expect($body['data'][0]['status'])->toBe('archived');
});

it('filters by ?search= matching name OR code OR city (case-insensitive)', function (): void {
    Branch::factory()->forCompany($this->company)->create([
        'code' => 'B-HQ',
        'name' => 'Phnom Penh HQ',
        'city' => 'Phnom Penh',
    ]);
    Branch::factory()->forCompany($this->company)->create([
        'code' => 'B-SHV',
        'name' => 'Coast Office',
        'city' => 'Sihanoukville',
    ]);

    $this->actingAs($this->admin);

    // Name match
    expect($this->getJson('/api/v1/hrm/branches?search=phnom')->json('meta.total'))->toBe(1);
    // Code match
    expect($this->getJson('/api/v1/hrm/branches?search=B-SHV')->json('meta.total'))->toBe(1);
    // City match — exercises the SEARCH branch unique to Branch (Department + Position don't have city)
    expect($this->getJson('/api/v1/hrm/branches?search=sihanoukville')->json('meta.total'))->toBe(1);
    // No match
    expect($this->getJson('/api/v1/hrm/branches?search=nonexistent')->json('meta.total'))->toBe(0);
});

it('hides soft-deleted branches from the index', function (): void {
    Branch::factory()->forCompany($this->company)->count(2)->create();
    $toDelete = Branch::factory()->forCompany($this->company)->create([
        'name' => 'Soft-Deleted Marker',
    ]);
    $toDelete->delete();

    $this->actingAs($this->admin);
    $body = $this->getJson('/api/v1/hrm/branches')->assertOk()->json();

    expect($body['meta']['total'])->toBe(2);
    expect(json_encode($body))->not->toContain('Soft-Deleted Marker');
});
