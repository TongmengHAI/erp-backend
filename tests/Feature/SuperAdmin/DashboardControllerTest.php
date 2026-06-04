<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────────
// DashboardControllerTest — 5-test pattern for GET /api/v1/super-admin/dashboard:
//
//   • happy path     — SA gets the 5-metric + 2-list payload
//   • 401            — unauthenticated
//   • 404 not 403    — tenant_admin gets 404 per Q8 (SuperAdminGuard)
//   • response shape — all 5 top-level keys present + types correct
//   • window_days    — matches SuperAdminDashboardService::RECENT_WINDOW_DAYS
//                      (so the SPA's "Last X days" copy can't drift)
//
// No 422 — endpoint accepts no body or query parameters.
// ─────────────────────────────────────────────────────────────────────────────

use App\Domain\Platform\Models\TenantModule;
use App\Domain\Platform\Services\SuperAdminDashboardService;
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

    $this->sa = User::factory()->superAdmin()->create();
});

it('happy path: SA gets the 5-metric + 2-list payload', function (): void {
    Tenant::factory()->count(3)->create();
    Tenant::factory()->suspended()->withoutEntitlement()->create();

    $this->actingAs($this->sa);
    $response = $this->getJson('/api/v1/super-admin/dashboard');

    $response->assertOk();
    // 5 metric blocks + window_days.
    $response->assertJsonStructure([
        'data' => [
            'tenant_status_counts' => ['total', 'active', 'suspended', 'archived'],
            'tenants_by_module',
            'recent_signups',
            'recent_suspensions',
            'window_days',
        ],
    ]);

    expect($response->json('data.tenant_status_counts.total'))->toBe(4);
    expect($response->json('data.tenant_status_counts.active'))->toBe(3);
    expect($response->json('data.tenant_status_counts.suspended'))->toBe(1);
});

it('401 when called with no authenticated session', function (): void {
    $this->getJson('/api/v1/super-admin/dashboard')->assertStatus(401);
});

it('LOAD-BEARING: 404 (not 403) when tenant_admin tries to access dashboard (Q8)', function (): void {
    $tenant = Tenant::factory()->create();
    $company = Company::factory()->forTenant($tenant)->create();
    $admin = User::factory()->forTenant($tenant)->create([
        'default_company_id' => $company->id,
        'current_company_id' => $company->id,
    ]);
    $admin->assignTenantRole($tenant, 'tenant_admin');

    $this->actingAs($admin)
        ->getJson('/api/v1/super-admin/dashboard')
        ->assertStatus(404);
});

it('window_days matches SuperAdminDashboardService::RECENT_WINDOW_DAYS (no SPA-copy drift)', function (): void {
    $this->actingAs($this->sa);
    $response = $this->getJson('/api/v1/super-admin/dashboard')->assertOk();

    expect($response->json('data.window_days'))->toBe(SuperAdminDashboardService::RECENT_WINDOW_DAYS);
});

it('tenants_by_module pivots the underlying tenant_modules state', function (): void {
    // 2 tenants with HRM active (factory default), 1 with HRM disabled.
    Tenant::factory()->count(2)->create();
    $disabled = Tenant::factory()->withoutEntitlement()->create();
    TenantModule::factory()->forTenant($disabled)->disabled()->create();

    $this->actingAs($this->sa);
    $body = $this->getJson('/api/v1/super-admin/dashboard')->assertOk()->json();

    expect($body['data']['tenants_by_module'])->toHaveCount(1);
    expect($body['data']['tenants_by_module'][0]['module_key'])->toBe('hrm');
    expect($body['data']['tenants_by_module'][0]['active_count'])->toBe(2);
    expect($body['data']['tenants_by_module'][0]['disabled_count'])->toBe(1);
});

it('recent_signups + recent_suspensions surface the tenant brief shape', function (): void {
    Tenant::factory()->create(['name' => 'Just Onboarded']);
    Tenant::factory()->suspended()->create(['name' => 'Just Suspended']);

    $this->actingAs($this->sa);
    $body = $this->getJson('/api/v1/super-admin/dashboard')->assertOk()->json();

    expect($body['data']['recent_signups'])->not->toBeEmpty();
    expect($body['data']['recent_signups'][0])->toHaveKeys(['id', 'slug', 'name', 'status']);
    expect($body['data']['recent_suspensions'])->not->toBeEmpty();
    expect($body['data']['recent_suspensions'][0])->toHaveKeys(['id', 'slug', 'name', 'status']);
});
