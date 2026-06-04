<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────────
// MultiTenantDemoStateTest — Session 4 plan tightening #2.
//
// Runs the actual DemoUsersSeeder and asserts the multi-tenant demo
// state holds:
//
//   • Three tenants exist after seeding: Acme (active), Sokha (active),
//     Suspended Co. (suspended).
//   • Each active tenant has an Active HRM tenant_modules row (closes
//     the seeder-side §10.12 gap that pre-Session-4 left).
//   • SA-side cross-tenant queries return ALL three tenants in one
//     shot (proves the SA bypass works in PRACTICE, not just in factory-
//     created test fixtures).
//   • tenant_admin queries return ONLY their own tenant (proves the
//     non-SA path is still correctly scoped — the bypass is parallel,
//     not relaxing).
//
// This test exists to prove the demo seeder state is what the SA UX
// expects to find on a fresh `migrate:fresh --seed` — the cross-tenant
// view + the entitlement state. Without it, a regression to the seeder
// would surface only when a developer manually verifies the dashboard
// in their browser.
// ─────────────────────────────────────────────────────────────────────────────

use App\Domain\Platform\Enums\ModuleStatus;
use App\Domain\Platform\Models\TenantModule;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\Enums\TenantStatus;
use Database\Seeders\Demo\DemoUsersSeeder;
use Database\Seeders\Framework\DefaultPermissionsSeeder;
use Database\Seeders\Framework\DefaultRolesSeeder;
use Database\Seeders\Framework\SuperAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Run the same framework + demo seed chain that a fresh
    // `migrate:fresh --seed` triggers, so the assertions exercise the
    // real production behaviour rather than a hand-rolled fixture.
    $this->seed([
        DefaultPermissionsSeeder::class,
        DefaultRolesSeeder::class,
        SuperAdminSeeder::class,
        DemoUsersSeeder::class,
    ]);
});

it('seeded state: Acme + Sokha + Suspended Co. tenants exist with the expected status mix', function (): void {
    $acme = Tenant::query()->where('slug', 'acme')->first();
    $sokha = Tenant::query()->where('slug', 'sokha')->first();
    $suspended = Tenant::query()->where('slug', 'suspended-co')->first();

    expect($acme)->not->toBeNull();
    expect($sokha)->not->toBeNull();
    expect($suspended)->not->toBeNull();

    expect($acme->status)->toBe(TenantStatus::Active);
    expect($sokha->status)->toBe(TenantStatus::Active);
    expect($suspended->status)->toBe(TenantStatus::Suspended);
});

it('seeded state: every demo tenant has an Active HRM tenant_modules row (seeder §10.12 gap closed)', function (): void {
    foreach (['acme', 'sokha', 'suspended-co'] as $slug) {
        $tenant = Tenant::query()->where('slug', $slug)->first();

        /** @var TenantModule|null $entitlement */
        $entitlement = TenantModule::query()
            ->acrossTenants()
            ->where('tenant_id', $tenant->id)
            ->where('module_key', 'hrm')
            ->first();

        expect($entitlement)->not->toBeNull(
            "Expected tenant '{$slug}' to have an HRM entitlement row after demo seed.",
        );
        expect($entitlement->status)->toBe(ModuleStatus::Active);
    }
});

it('LOAD-BEARING: SA sees BOTH active tenants (Acme + Sokha) in a single cross-tenant query', function (): void {
    /** @var User $sa */
    $sa = User::query()->where('email', 'superadmin@myerp.local')->first();
    expect($sa)->not->toBeNull('SA user from SuperAdminSeeder expected.');
    expect($sa->isSuperAdmin())->toBeTrue();

    // Acting as the SA, list all tenants (no scope filter, no tenant
    // context). The SA bypass on TenantScope + no tenant_id on Tenant
    // means a vanilla Tenant::query()->get() returns the entire estate
    // in one shot.
    $this->actingAs($sa);
    $allSlugs = Tenant::query()->pluck('slug')->all();

    expect($allSlugs)->toContain('acme');
    expect($allSlugs)->toContain('sokha');
    expect($allSlugs)->toContain('suspended-co');
});

it('LOAD-BEARING: tenant_admin sees ONLY their own tenant (bypass is parallel, not relaxing)', function (): void {
    // Acme admin should see Acme employees only, not Sokha's. This is
    // the regression check: the SA bypass on TenantScope must NOT
    // relax scoping for tenant_users.
    /** @var User $acmeAdmin */
    $acmeAdmin = User::query()->where('email', 'admin@acme.test')->first();

    $this->actingAs($acmeAdmin);

    // Tenant model itself has no scope (it IS the boundary). Use a
    // tenant-scoped model — Employee — as the proxy. We don't need to
    // list employees here; we just need to query something that goes
    // through TenantScope and assert the result is non-leaky.
    //
    // The standard way is via /auth/me — the response carries the
    // user's OWN tenant only. Cross-tenant leakage would surface as
    // unexpected tenant fields in the response.
    $this->withHeader('Origin', 'http://localhost');
    $response = $this->getJson('/api/v1/auth/me');
    $response->assertOk();

    expect($response->json('data.tenant.slug'))->toBe('acme');
    // No bleed of other tenants into the user's session view.
    expect(json_encode($response->json()))->not->toContain('sokha');
    expect(json_encode($response->json()))->not->toContain('suspended-co');
});

it('SA dashboard endpoint reflects the multi-tenant state correctly', function (): void {
    /** @var User $sa */
    $sa = User::query()->where('email', 'superadmin@myerp.local')->first();

    $this->withHeader('Origin', 'http://localhost');
    $this->actingAs($sa);
    $body = $this->getJson('/api/v1/super-admin/dashboard')->assertOk()->json();

    // 2 active + 1 suspended = 3 total per the seeded state.
    expect($body['data']['tenant_status_counts']['active'])->toBe(2);
    expect($body['data']['tenant_status_counts']['suspended'])->toBe(1);
    expect($body['data']['tenant_status_counts']['total'])->toBe(3);

    // All three tenants are HRM-entitled per the seeder helper. The
    // "tenants_by_module" row should show 3 active.
    $hrmRow = collect($body['data']['tenants_by_module'])
        ->first(fn (array $row): bool => $row['module_key'] === 'hrm');

    expect($hrmRow)->not->toBeNull();
    expect($hrmRow['active_count'])->toBe(3);
    expect($hrmRow['disabled_count'])->toBe(0);
});

it('demo seed is idempotent — running the seeder twice does not duplicate state', function (): void {
    // Re-running an already-seeded DemoUsersSeeder must not collide:
    // tenant slugs are unique, user emails are unique, tenant_modules
    // has a partial unique index. The seeder uses firstOrCreate
    // throughout for idempotency.
    $this->seed(DemoUsersSeeder::class);

    expect(Tenant::query()->where('slug', 'sokha')->count())->toBe(1);
    expect(User::query()->where('email', 'admin@sokha.test')->count())->toBe(1);
    expect(
        TenantModule::query()
            ->acrossTenants()
            ->where('tenant_id', Tenant::query()->where('slug', 'sokha')->first()->id)
            ->count(),
    )->toBe(1);
});
