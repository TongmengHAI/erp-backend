<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────────
// EnforceModuleEntitlementTest — end-to-end HTTP tests for the
// 'module:hrm' middleware, applied to /api/v1/hrm/* AND /api/v1/admin/hrm/*.
//
// Behaviour matrix:
//
//   tenant_user with HRM active            → 200 (route runs)
//   tenant_user with HRM disabled          → 403 module_not_entitled
//   tenant_user with HRM row soft-deleted  → 403 module_not_entitled
//   tenant_user with NO HRM row            → 403 module_not_entitled
//   tenant_admin on admin/hrm with HRM
//     disabled (no self-rescue)            → 403 module_not_entitled
//   super_admin                            → 200 regardless of entitlement
// ─────────────────────────────────────────────────────────────────────────────

use App\Domain\Platform\Models\TenantModule;
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

    // Standard per-test fixture: one tenant (Active HRM entitlement by
    // factory default — TenantFactory's afterCreating() hook mirrors
    // the production backfill). Tests that need a different entitlement
    // state opt out via ->withoutEntitlement() and then create a
    // specific row (Disabled, soft-deleted, etc.).
    $this->tenant = Tenant::factory()->create();
    $this->company = Company::factory()->forTenant($this->tenant)->create();
    $this->user = User::factory()->forTenant($this->tenant)->create([
        'default_company_id' => $this->company->id,
        'current_company_id' => $this->company->id,
    ]);
    $this->user->assignTenantRole($this->tenant, 'tenant_admin');
});

it('tenant_user with HRM Active reaches /api/v1/hrm/employees', function (): void {
    // Default TenantFactory grants Active HRM — no manual setup needed.
    $this->actingAs($this->user)
        ->getJson('/api/v1/hrm/employees')
        ->assertOk();
});

it('LOAD-BEARING: tenant_user with HRM Disabled gets 403 module_not_entitled with the module key', function (): void {
    // Override the factory default: tenant gets Disabled HRM.
    $tenant = Tenant::factory()->withoutEntitlement()->create();
    $company = Company::factory()->forTenant($tenant)->create();
    $user = User::factory()->forTenant($tenant)->create([
        'default_company_id' => $company->id,
        'current_company_id' => $company->id,
    ]);
    $user->assignTenantRole($tenant, 'tenant_admin');
    TenantModule::factory()->forTenant($tenant)->disabled()->create();

    $this->actingAs($user);
    $response = $this->getJson('/api/v1/hrm/employees');

    $response->assertStatus(403);
    $response->assertJsonPath('error_code', 'module_not_entitled');
    $response->assertJsonPath('module', 'hrm');
});

it('tenant_user with NO HRM row gets 403 module_not_entitled (no row = no entitlement)', function (): void {
    // Override the factory default: tenant gets NO entitlement row.
    $tenant = Tenant::factory()->withoutEntitlement()->create();
    $company = Company::factory()->forTenant($tenant)->create();
    $user = User::factory()->forTenant($tenant)->create([
        'default_company_id' => $company->id,
        'current_company_id' => $company->id,
    ]);
    $user->assignTenantRole($tenant, 'tenant_admin');

    $this->actingAs($user);
    $response = $this->getJson('/api/v1/hrm/employees');

    $response->assertStatus(403);
    $response->assertJsonPath('error_code', 'module_not_entitled');
});

it('tenant_user with soft-deleted HRM row gets 403 module_not_entitled (revoked rows do not entitle)', function (): void {
    $tenant = Tenant::factory()->withoutEntitlement()->create();
    $company = Company::factory()->forTenant($tenant)->create();
    $user = User::factory()->forTenant($tenant)->create([
        'default_company_id' => $company->id,
        'current_company_id' => $company->id,
    ]);
    $user->assignTenantRole($tenant, 'tenant_admin');
    $row = TenantModule::factory()->forTenant($tenant)->create();
    $row->delete();

    $this->actingAs($user);
    $response = $this->getJson('/api/v1/hrm/employees');

    $response->assertStatus(403);
    $response->assertJsonPath('error_code', 'module_not_entitled');
});

it('LOAD-BEARING: admin/hrm/settings is gated by the SAME module:hrm middleware (no tenant_admin self-rescue)', function (): void {
    // The plan's Q-related decision: when HRM is disabled, tenant_admin
    // can't see HRM Settings either. Only SA controls entitlement; the
    // tenant_admin route group carries the SAME 'module:hrm' middleware.
    $tenant = Tenant::factory()->withoutEntitlement()->create();
    $company = Company::factory()->forTenant($tenant)->create();
    $user = User::factory()->forTenant($tenant)->create([
        'default_company_id' => $company->id,
        'current_company_id' => $company->id,
    ]);
    $user->assignTenantRole($tenant, 'tenant_admin');
    TenantModule::factory()->forTenant($tenant)->disabled()->create();

    $this->actingAs($user);
    $response = $this->getJson('/api/v1/admin/hrm/settings');

    $response->assertStatus(403);
    $response->assertJsonPath('error_code', 'module_not_entitled');
});

it('LOAD-BEARING: super_admin bypasses module:hrm middleware regardless of entitlement', function (): void {
    // Disable HRM for the tenant — but the SA hitting the tenant's
    // endpoints should still get through (EnforceModuleEntitlement's
    // SA bypass short-circuits before the tenant_id lookup).
    $tenant = Tenant::factory()->withoutEntitlement()->create();
    TenantModule::factory()->forTenant($tenant)->disabled()->create();

    $sa = User::factory()->superAdmin()->create();
    $this->actingAs($sa);

    // The SA hitting /hrm/employees won't have a current_company (no
    // ResolveCompany since SA bypasses), so the company-required
    // middleware on the parent group could still 401. We're only
    // testing the module gate here, so use a route that goes through
    // the same middleware stack — admin/hrm/settings, which SA
    // also bypasses thanks to SA→ResolveTenant/ResolveCompany skips.
    // The combined effect: SA reaches the controller, and we assert
    // the response is NOT 403 module_not_entitled. The controller may
    // 500 or do something else if SA-handling isn't wired (which is
    // fine — the middleware's job is to NOT block SA, not to make
    // the downstream controller SA-aware).
    $response = $this->getJson('/api/v1/admin/hrm/settings');

    // module_not_entitled MUST NOT fire.
    expect($response->json('error_code'))->not->toBe('module_not_entitled');
});
