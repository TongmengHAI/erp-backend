<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────────
// MeTest — covers GET /api/v1/auth/me.
//
// §7.D pattern notes:
//   - 403 (authorization failure) is N/A. Every authenticated user can fetch
//     their own auth context. There is no permission to deny against — this
//     endpoint exists *to* drive permission checks elsewhere.
//   - 422 (validation failure) is N/A. /me accepts no request body or query.
//   - 429 (rate limit) is wired (throttle:60,1) but not test-asserted — making
//     61 real HTTP calls in a unit test would be slow for marginal value;
//     Laravel's throttle middleware is itself well-tested.
// ─────────────────────────────────────────────────────────────────────────────

use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\Framework\DefaultPermissionsSeeder;
use Database\Seeders\Framework\DefaultRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // /me lives inside auth:sanctum + ResolveTenant; Origin header lets
    // EnsureFrontendRequestsAreStateful recognise the SPA request and start
    // the session middleware. (Same pattern as LoginTest.)
    $this->withHeader('Origin', 'http://localhost');

    // Default permissions + roles are needed for the user's role assignment
    // to map onto observable permission names in the /me response.
    $this->seed([
        DefaultPermissionsSeeder::class,
        DefaultRolesSeeder::class,
    ]);
});

it('returns user + tenant + roles + permissions for an authenticated user in an active tenant', function (): void {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->forTenant($tenant)->create();
    $user->assignTenantRole($tenant, 'accountant');

    $this->actingAs($user);

    $response = $this->getJson('/api/v1/auth/me');

    $response->assertOk();
    $response->assertJsonPath('data.user.id', $user->id);
    $response->assertJsonPath('data.user.email', $user->email);
    $response->assertJsonPath('data.tenant.id', $tenant->id);
    $response->assertJsonPath('data.tenant.functional_currency', $tenant->functional_currency);
    $response->assertJsonPath('data.tenant.country_code', $tenant->country_code);
    $response->assertJsonPath('data.tenant.timezone', $tenant->timezone);
    $response->assertExactJson([
        'data' => [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
            ],
            'tenant' => [
                'id' => $tenant->id,
                'slug' => $tenant->slug,
                'name' => $tenant->name,
                'country_code' => $tenant->country_code,
                'default_currency' => $tenant->default_currency,
                'functional_currency' => $tenant->functional_currency,
                'timezone' => $tenant->timezone,
            ],
            'roles' => ['accountant'],
            'permissions' => [
                'accounting.journal_entry.view',
                'accounting.journal_entry.create',
            ],
        ],
    ]);
});

it('returns 401 when called with no authenticated session', function (): void {
    $this->getJson('/api/v1/auth/me')->assertStatus(401);
});

it('returns 401 with error_code=tenant_inactive when the user current tenant is suspended', function (): void {
    $tenant = Tenant::factory()->suspended()->create();
    $user = User::factory()->forTenant($tenant)->create();

    $this->actingAs($user);

    $response = $this->getJson('/api/v1/auth/me');

    $response->assertStatus(401);
    $response->assertJsonPath('error_code', 'tenant_inactive');
    // Body MUST NOT leak suspension details beyond the stable error_code.
    expect($response->json())->toHaveKeys(['message', 'error_code']);
    expect($response->json())->not->toHaveKey('data');
});

it('isolates tenants — only the current tenant data is returned, no leakage of other tenants', function (): void {
    $tenant1 = Tenant::factory()->create(['name' => 'First Tenant']);
    $tenant2 = Tenant::factory()->create(['name' => 'Second Tenant Name LeakedIfYouSeeThis']);

    $userA = User::factory()->forTenant($tenant1)->create();
    $userA->assignTenantRole($tenant1, 'tenant_admin');

    // userA also has a role in tenant2 — should NOT appear in their /me
    // because ResolveTenant pins the registrar team_id to tenant1.
    $userA->assignTenantRole($tenant2, 'viewer');

    $this->actingAs($userA);

    $response = $this->getJson('/api/v1/auth/me');

    $response->assertOk();
    $response->assertJsonPath('data.tenant.id', $tenant1->id);
    $response->assertJsonPath('data.tenant.name', 'First Tenant');
    $response->assertJsonPath('data.roles', ['tenant_admin']);

    expect(json_encode($response->json()))->not->toContain('Second Tenant Name LeakedIfYouSeeThis');
    expect(json_encode($response->json()))->not->toContain('viewer');
});

it('the response payload contains exactly { data: { user, tenant, roles, permissions } } and excludes sensitive fields', function (): void {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->forTenant($tenant)->create();
    $user->assignTenantRole($tenant, 'viewer');

    $this->actingAs($user);

    $body = $this->getJson('/api/v1/auth/me')->json();

    expect(array_keys($body))->toBe(['data']);
    expect(array_keys($body['data']))->toEqualCanonicalizing(['user', 'tenant', 'roles', 'permissions']);
    expect($body['data']['user'])->not->toHaveKey('password');
    expect($body['data']['user'])->not->toHaveKey('remember_token');
    expect($body['data']['user'])->not->toHaveKey('tenant_id');
    expect($body['data']['user'])->not->toHaveKey('current_tenant_id');
    expect($body['data']['tenant'])->not->toHaveKey('settings');
    expect($body['data']['tenant'])->not->toHaveKey('status');
    expect($body['data']['tenant'])->not->toHaveKey('legal_name');
    expect($body['data']['tenant'])->not->toHaveKey('deleted_at');
});

it('reflects role grants scoped via Spatie teams — different role per tenant returns the current-tenant role only', function (): void {
    // userB has different roles in two tenants. Their current tenant pins
    // the result to one of them.
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $userB = User::factory()->forTenant($tenantA)->create();
    $userB->assignTenantRole($tenantA, 'accountant');
    $userB->assignTenantRole($tenantB, 'tenant_admin');

    $this->actingAs($userB);

    $response = $this->getJson('/api/v1/auth/me')->assertOk();

    expect($response->json('data.tenant.id'))->toBe($tenantA->id);
    expect($response->json('data.roles'))->toBe(['accountant']);
    expect($response->json('data.permissions'))->toEqualCanonicalizing([
        'accounting.journal_entry.view',
        'accounting.journal_entry.create',
    ]);

    // No tenant_admin permission leaked.
    expect($response->json('data.permissions'))->not->toContain('tenant.settings.manage');
});
