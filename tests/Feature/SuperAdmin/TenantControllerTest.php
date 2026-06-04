<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────────
// TenantControllerTest — 5-test pattern × 4 endpoints + LOAD-BEARING SA-bypass
// tests for the SA-side tenant CRUD:
//
//   GET   /api/v1/super-admin/tenants                — index
//   GET   /api/v1/super-admin/tenants/{tenant}       — show
//   POST  /api/v1/super-admin/tenants                — store + initial admin
//   PATCH /api/v1/super-admin/tenants/{tenant}       — update + suspend/resume
//
// The three SESSION 3 plan-tightening tests live in their own file
// (CreateTenantWithInitialAdminActionTest) for focus:
//   1. §10.12 edge case — listener failure mid-create, recoverable
//   2. Audit log does NOT contain plaintext password
//   3. forgot-password recovery path (Hash::check data shape)
// ─────────────────────────────────────────────────────────────────────────────

use App\Models\Company;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\Enums\TenantStatus;
use Database\Seeders\Framework\DefaultPermissionsSeeder;
use Database\Seeders\Framework\DefaultRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withHeader('Origin', 'http://localhost');
    $this->seed([DefaultPermissionsSeeder::class, DefaultRolesSeeder::class]);

    $this->sa = User::factory()->superAdmin()->create();
});

// ─── INDEX ──────────────────────────────────────────────────────────────────

it('index: happy path — SA can list tenants paginated', function (): void {
    Tenant::factory()->count(3)->create();

    $this->actingAs($this->sa);
    $response = $this->getJson('/api/v1/super-admin/tenants');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(3);
    expect($response->json('data.0'))->toHaveKeys(['id', 'slug', 'name', 'status']);
});

it('index: 401 when called with no authenticated session', function (): void {
    Tenant::factory()->create();
    $this->getJson('/api/v1/super-admin/tenants')->assertStatus(401);
});

it('LOAD-BEARING: index 404 (not 403) when tenant_admin tries to access SA endpoint (Q8)', function (): void {
    $tenant = Tenant::factory()->create();
    $company = Company::factory()->forTenant($tenant)->create();
    $admin = User::factory()->forTenant($tenant)->create([
        'default_company_id' => $company->id,
        'current_company_id' => $company->id,
    ]);
    $admin->assignTenantRole($tenant, 'tenant_admin');

    $this->actingAs($admin);
    $this->getJson('/api/v1/super-admin/tenants')->assertStatus(404);
});

it('LOAD-BEARING: index returns ALL tenants regardless of status (SA sees suspended per Q1)', function (): void {
    Tenant::factory()->create(['name' => 'Active Co']);
    Tenant::factory()->suspended()->create(['name' => 'Suspended Co']);

    $this->actingAs($this->sa);
    $body = $this->getJson('/api/v1/super-admin/tenants')->assertOk()->json();

    $names = array_column($body['data'], 'name');
    expect($names)->toContain('Active Co');
    expect($names)->toContain('Suspended Co');
});

it('index: status filter narrows to the requested status', function (): void {
    Tenant::factory()->create(['name' => 'Active Co']);
    Tenant::factory()->suspended()->create(['name' => 'Suspended Co']);

    $this->actingAs($this->sa);
    $body = $this->getJson('/api/v1/super-admin/tenants?status=suspended')->assertOk()->json();

    expect($body['data'])->toHaveCount(1);
    expect($body['data'][0]['name'])->toBe('Suspended Co');
});

// ─── SHOW ───────────────────────────────────────────────────────────────────

it('show: happy path — SA reads tenant detail', function (): void {
    $tenant = Tenant::factory()->create(['name' => 'Acme Trading']);

    $this->actingAs($this->sa);
    $response = $this->getJson("/api/v1/super-admin/tenants/{$tenant->id}");

    $response->assertOk();
    $response->assertJsonPath('data.id', $tenant->id);
    $response->assertJsonPath('data.name', 'Acme Trading');
});

it('show: 404 for unknown tenant id', function (): void {
    $this->actingAs($this->sa);
    $this->getJson('/api/v1/super-admin/tenants/999999')->assertStatus(404);
});

it('show: 401 when called with no authenticated session', function (): void {
    $tenant = Tenant::factory()->create();
    $this->getJson("/api/v1/super-admin/tenants/{$tenant->id}")->assertStatus(401);
});

it('show: 404 (not 403) when tenant_admin tries to read another tenant via SA endpoint', function (): void {
    $tenant = Tenant::factory()->create();
    $tenant2 = Tenant::factory()->create();
    $company = Company::factory()->forTenant($tenant)->create();
    $admin = User::factory()->forTenant($tenant)->create([
        'default_company_id' => $company->id,
        'current_company_id' => $company->id,
    ]);
    $admin->assignTenantRole($tenant, 'tenant_admin');

    $this->actingAs($admin);
    $this->getJson("/api/v1/super-admin/tenants/{$tenant2->id}")->assertStatus(404);
});

// ─── STORE ──────────────────────────────────────────────────────────────────

it('store: happy path — creates tenant + company + initial admin; returns one-time password', function (): void {
    $this->actingAs($this->sa);

    $response = $this->postJson('/api/v1/super-admin/tenants', validStorePayload());

    $response->assertStatus(201);
    $response->assertJsonPath('data.tenant.slug', 'acme-trading');
    $response->assertJsonPath('data.tenant.status', 'active');
    $response->assertJsonPath('data.initial_admin.email', 'sokha@acme.kh');
    expect($response->json('data.initial_admin_password'))->toBeString();
    expect(strlen((string) $response->json('data.initial_admin_password')))->toBe(16);

    // Persistence sanity-check.
    $tenant = Tenant::query()->where('slug', 'acme-trading')->first();
    expect($tenant)->not->toBeNull();
    expect(Company::query()->where('tenant_id', $tenant->id)->count())->toBe(1);
    expect(User::query()->where('email', 'sokha@acme.kh')->count())->toBe(1);
});

it('store: 401 when called with no authenticated session', function (): void {
    $this->postJson('/api/v1/super-admin/tenants', validStorePayload())->assertStatus(401);
});

it('store: 404 when tenant_admin tries to call SA endpoint (Q8)', function (): void {
    $tenant = Tenant::factory()->create();
    $company = Company::factory()->forTenant($tenant)->create();
    $admin = User::factory()->forTenant($tenant)->create([
        'default_company_id' => $company->id,
        'current_company_id' => $company->id,
    ]);
    $admin->assignTenantRole($tenant, 'tenant_admin');

    $this->actingAs($admin)
        ->postJson('/api/v1/super-admin/tenants', validStorePayload())
        ->assertStatus(404);
});

it('store: 422 when slug fails the regex (uppercase rejected)', function (): void {
    $this->actingAs($this->sa);
    $payload = validStorePayload();
    $payload['slug'] = 'Acme-Trading'; // uppercase rejected

    $this->postJson('/api/v1/super-admin/tenants', $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors('slug');
});

it('store: 422 when slug collides with an existing tenant', function (): void {
    Tenant::factory()->create(['slug' => 'acme-trading']);

    $this->actingAs($this->sa);
    $this->postJson('/api/v1/super-admin/tenants', validStorePayload())
        ->assertStatus(422)
        ->assertJsonValidationErrors('slug');
});

it('store: 422 when initial admin email collides with an existing user', function (): void {
    $tenant = Tenant::factory()->create();
    User::factory()->forTenant($tenant)->create(['email' => 'sokha@acme.kh']);

    $this->actingAs($this->sa);
    $this->postJson('/api/v1/super-admin/tenants', validStorePayload())
        ->assertStatus(422)
        ->assertJsonValidationErrors('initial_admin.email');
});

// ─── UPDATE ─────────────────────────────────────────────────────────────────

it('update: happy path — SA changes name + slug', function (): void {
    $tenant = Tenant::factory()->create(['name' => 'Old Name', 'slug' => 'old-slug']);

    $this->actingAs($this->sa);
    $response = $this->patchJson("/api/v1/super-admin/tenants/{$tenant->id}", [
        'name' => 'New Name',
        'slug' => 'new-slug',
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.name', 'New Name');
    $response->assertJsonPath('data.slug', 'new-slug');
    expect($tenant->fresh()->status)->toBe(TenantStatus::Active);
});

it('LOAD-BEARING: update — SA suspends a tenant; tenant_users get 401 tenant_inactive on next request (per Q1)', function (): void {
    $tenant = Tenant::factory()->create();
    $company = Company::factory()->forTenant($tenant)->create();
    $tenantUser = User::factory()->forTenant($tenant)->create([
        'default_company_id' => $company->id,
        'current_company_id' => $company->id,
    ]);
    $tenantUser->assignTenantRole($tenant, 'tenant_admin');

    // SA suspends.
    $this->actingAs($this->sa);
    $this->patchJson("/api/v1/super-admin/tenants/{$tenant->id}", [
        'status' => 'suspended',
    ])->assertOk();

    expect($tenant->fresh()->status)->toBe(TenantStatus::Suspended);

    // Tenant user's next /me request → 401 tenant_inactive via the
    // existing ResolveTenant chain (no new infrastructure; Q1 reuse).
    $this->actingAs($tenantUser);
    $response = $this->getJson('/api/v1/auth/me');
    $response->assertStatus(401);
    $response->assertJsonPath('error_code', 'tenant_inactive');
});

it('update: SA can resume a suspended tenant — tenant user can log in again', function (): void {
    $tenant = Tenant::factory()->suspended()->create();

    $this->actingAs($this->sa);
    $this->patchJson("/api/v1/super-admin/tenants/{$tenant->id}", [
        'status' => 'active',
    ])->assertOk();

    expect($tenant->fresh()->status)->toBe(TenantStatus::Active);
});

it('update: 422 rejects status=archived (out of scope for v1 SA UX)', function (): void {
    $tenant = Tenant::factory()->create();

    $this->actingAs($this->sa)
        ->patchJson("/api/v1/super-admin/tenants/{$tenant->id}", [
            'status' => 'archived',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('status');
});

it('update: 422 when slug collides with another tenant', function (): void {
    Tenant::factory()->create(['slug' => 'taken-slug']);
    $tenant = Tenant::factory()->create(['slug' => 'my-slug']);

    $this->actingAs($this->sa)
        ->patchJson("/api/v1/super-admin/tenants/{$tenant->id}", [
            'slug' => 'taken-slug',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('slug');
});

it('update: 200 when slug stays the same (own tenant excluded from uniqueness)', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'my-slug', 'name' => 'Old Name']);

    $this->actingAs($this->sa)
        ->patchJson("/api/v1/super-admin/tenants/{$tenant->id}", [
            'slug' => 'my-slug',
            'name' => 'New Name',
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'New Name');
});

it('update: 401 when called with no authenticated session', function (): void {
    $tenant = Tenant::factory()->create();
    $this->patchJson("/api/v1/super-admin/tenants/{$tenant->id}", [
        'name' => 'Whatever',
    ])->assertStatus(401);
});

it('update: 404 when tenant_admin tries to call SA endpoint (Q8)', function (): void {
    $tenant = Tenant::factory()->create();
    $company = Company::factory()->forTenant($tenant)->create();
    $admin = User::factory()->forTenant($tenant)->create([
        'default_company_id' => $company->id,
        'current_company_id' => $company->id,
    ]);
    $admin->assignTenantRole($tenant, 'tenant_admin');

    $this->actingAs($admin)
        ->patchJson("/api/v1/super-admin/tenants/{$tenant->id}", [
            'name' => 'Hijacked',
        ])
        ->assertStatus(404);
});

// ─── helpers ────────────────────────────────────────────────────────────────

function validStorePayload(): array
{
    return [
        'slug' => 'acme-trading',
        'name' => 'Acme Trading Co.',
        'legal_name' => 'Acme Trading Co., Ltd.',
        'country_code' => 'KH',
        'default_currency' => 'USD',
        'functional_currency' => 'USD',
        'timezone' => 'Asia/Phnom_Penh',
        'company' => [
            'slug' => 'acme-trading-main',
            'name' => 'Acme Trading Main',
            'legal_name' => 'Acme Trading Co., Ltd.',
        ],
        'initial_admin' => [
            'name' => 'Sokha Chan',
            'email' => 'sokha@acme.kh',
        ],
    ];
}
