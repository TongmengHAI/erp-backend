<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────────
// PositionIndexTest — covers GET /api/v1/hrm/positions.
// Mirror of DepartmentIndexTest. Standard 5-test pattern + cross-tenant +
// cross-company isolation + search/filter.
// ─────────────────────────────────────────────────────────────────────────────

use App\Domain\HRM\Enums\PositionStatus;
use App\Domain\HRM\Models\Position;
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

it('returns a paginated list of positions scoped to the current tenant + company', function (): void {
    Position::factory()->forCompany($this->company)->count(3)->create();

    $this->actingAs($this->admin);
    $response = $this->getJson('/api/v1/hrm/positions');

    $response->assertOk();
    $response->assertJsonStructure([
        'data' => [['id', 'code', 'title', 'status']],
        'meta' => ['current_page', 'per_page', 'total'],
    ]);
    expect($response->json('meta.total'))->toBe(3);
});

it('returns 401 when called with no authenticated session', function (): void {
    $this->getJson('/api/v1/hrm/positions')->assertStatus(401);
});

it('returns 403 when the authenticated user lacks hrm.position.view permission', function (): void {
    $unprivileged = User::factory()->forTenant($this->tenant)->create([
        'default_company_id' => $this->company->id,
        'current_company_id' => $this->company->id,
    ]);

    $this->actingAs($unprivileged)
        ->getJson('/api/v1/hrm/positions')
        ->assertStatus(403);
});

it('returns 422 when an invalid status value is supplied as a filter', function (): void {
    $this->actingAs($this->admin)
        ->getJson('/api/v1/hrm/positions?status=not-a-real-status')
        ->assertStatus(422)
        ->assertJsonValidationErrors('status');
});

it('isolates cross-tenant — users in tenant A cannot see positions in tenant B', function (): void {
    Position::factory()->forCompany($this->company)->count(2)->create(['title' => 'Tenant A Position']);

    $otherTenant = Tenant::factory()->create();
    $otherCompany = Company::factory()->forTenant($otherTenant)->create();
    Position::factory()->forCompany($otherCompany)->create([
        'title' => 'Tenant B Leak Marker',
    ]);

    $this->actingAs($this->admin);
    $body = $this->getJson('/api/v1/hrm/positions')->assertOk()->json();

    expect($body['meta']['total'])->toBe(2);
    expect(json_encode($body))->not->toContain('Tenant B Leak Marker');
});

it('isolates cross-company — positions in another company within the same tenant are not listed', function (): void {
    Position::factory()->forCompany($this->company)->create(['title' => 'Visible']);

    $otherCompany = Company::factory()->forTenant($this->tenant)->create();
    Position::factory()->forCompany($otherCompany)->create([
        'title' => 'Other Company Leak Marker',
    ]);

    $this->actingAs($this->admin);
    $body = $this->getJson('/api/v1/hrm/positions')->assertOk()->json();

    expect($body['meta']['total'])->toBe(1);
    expect(json_encode($body))->not->toContain('Other Company Leak Marker');
});

it('filters by status when ?status= is supplied', function (): void {
    Position::factory()->forCompany($this->company)->count(2)->create(['status' => PositionStatus::Active]);
    Position::factory()->forCompany($this->company)->archived()->create();

    $this->actingAs($this->admin);
    $body = $this->getJson('/api/v1/hrm/positions?status=archived')->assertOk()->json();

    expect($body['meta']['total'])->toBe(1);
    expect($body['data'][0]['status'])->toBe('archived');
});

it('filters by ?search= matching either title or code (case-insensitive)', function (): void {
    Position::factory()->forCompany($this->company)->create([
        'code' => 'P-MGR',
        'title' => 'Operations Manager',
    ]);
    Position::factory()->forCompany($this->company)->create([
        'code' => 'P-FIN',
        'title' => 'Finance Lead',
    ]);

    $this->actingAs($this->admin);

    expect($this->getJson('/api/v1/hrm/positions?search=operations')->json('meta.total'))->toBe(1);
    expect($this->getJson('/api/v1/hrm/positions?search=P-FIN')->json('meta.total'))->toBe(1);
    expect($this->getJson('/api/v1/hrm/positions?search=nonexistent')->json('meta.total'))->toBe(0);
});

it('hides soft-deleted positions from the index', function (): void {
    Position::factory()->forCompany($this->company)->count(2)->create();
    $toDelete = Position::factory()->forCompany($this->company)->create([
        'title' => 'Soft-Deleted Marker',
    ]);
    $toDelete->delete();

    $this->actingAs($this->admin);
    $body = $this->getJson('/api/v1/hrm/positions')->assertOk()->json();

    expect($body['meta']['total'])->toBe(2);
    expect(json_encode($body))->not->toContain('Soft-Deleted Marker');
});
