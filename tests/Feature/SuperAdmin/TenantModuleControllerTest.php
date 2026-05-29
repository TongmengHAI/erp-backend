<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────────
// TenantModuleControllerTest — 5-test pattern + LOAD-BEARING tests for the
// SA-side entitlement endpoints:
//
//   GET   /api/v1/super-admin/tenants/{tenant}/modules — index
//   PATCH /api/v1/super-admin/tenants/{tenant}/modules — sync
//
// LOAD-BEARING tests:
//   - 404 (NOT 403) for non-SA accessing the endpoint (Q8 — security
//     through obscurity; SuperAdminGuard)
//   - sync populates enabled_by_user_id with the SA's id (pins the
//     OTHER invariant for nullable-but-populated rule; the migration
//     backfill uses NULL, but UI-driven syncs MUST capture the actor)
//   - audit row written on sync (TenantModule has Auditable; SA-side
//     compliance audit trail)
//   - cross-tenant: SA can sync modules for ANY tenant (proves the SA
//     bypasses bite through to the SA endpoints)
// ─────────────────────────────────────────────────────────────────────────────

use App\Domain\Platform\Enums\ModuleStatus;
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

    $this->sa = User::factory()->superAdmin()->create();
    // withoutEntitlement so each test sets up the exact tenant_modules
    // state it's exercising — the TenantFactory's default afterCreating()
    // would otherwise create an Active HRM row that collides with the
    // partial unique index when tests add their own.
    $this->tenant = Tenant::factory()->withoutEntitlement()->create();
});

// ─── INDEX ──────────────────────────────────────────────────────────────────

it('index: happy path — SA can list entitlement rows for a tenant', function (): void {
    TenantModule::factory()->forTenant($this->tenant)->create();

    $this->actingAs($this->sa);
    $response = $this->getJson("/api/v1/super-admin/tenants/{$this->tenant->id}/modules");

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.module_key'))->toBe('hrm');
    expect($response->json('data.0.status'))->toBe('active');
});

it('index: 401 when called with no authenticated session', function (): void {
    TenantModule::factory()->forTenant($this->tenant)->create();

    $this->getJson("/api/v1/super-admin/tenants/{$this->tenant->id}/modules")
        ->assertStatus(401);
});

it('LOAD-BEARING: index 404 (not 403) when tenant_admin tries to access SA endpoint (Q8)', function (): void {
    // Q8 locked decision: non-SA gets 404, not 403 — security through
    // obscurity. tenant_admin has no legitimate reason to know
    // /api/v1/super-admin/* exists.
    $company = Company::factory()->forTenant($this->tenant)->create();
    $admin = User::factory()->forTenant($this->tenant)->create([
        'default_company_id' => $company->id,
        'current_company_id' => $company->id,
    ]);
    $admin->assignTenantRole($this->tenant, 'tenant_admin');

    TenantModule::factory()->forTenant($this->tenant)->create();

    $this->actingAs($admin);
    $response = $this->getJson("/api/v1/super-admin/tenants/{$this->tenant->id}/modules");

    $response->assertStatus(404);
});

it('LOAD-BEARING: SA can index modules across tenants (TenantScope bypass + SA-side cross-tenant)', function (): void {
    // Three tenants, each with one entitlement row. SA can read all of
    // them via the SA endpoint without any tenant-context resolution.
    $tenantA = Tenant::factory()->withoutEntitlement()->create();
    $tenantB = Tenant::factory()->withoutEntitlement()->create();
    TenantModule::factory()->forTenant($tenantA)->create();
    TenantModule::factory()->forTenant($tenantB)->disabled()->create();

    $this->actingAs($this->sa);

    $a = $this->getJson("/api/v1/super-admin/tenants/{$tenantA->id}/modules")->assertOk();
    $b = $this->getJson("/api/v1/super-admin/tenants/{$tenantB->id}/modules")->assertOk();

    expect($a->json('data.0.tenant_id'))->toBe($tenantA->id);
    expect($a->json('data.0.status'))->toBe('active');
    expect($b->json('data.0.tenant_id'))->toBe($tenantB->id);
    expect($b->json('data.0.status'))->toBe('disabled');
});

// ─── SYNC ──────────────────────────────────────────────────────────────────

it('sync: happy path — SA flips HRM from active to disabled', function (): void {
    TenantModule::factory()->forTenant($this->tenant)->create();

    $this->actingAs($this->sa);
    $response = $this->patchJson(
        "/api/v1/super-admin/tenants/{$this->tenant->id}/modules",
        ['modules' => [['module_key' => 'hrm', 'status' => 'disabled']]],
    );

    $response->assertOk();
    expect($response->json('data.0.status'))->toBe('disabled');

    /** @var TenantModule $row */
    $row = TenantModule::query()
        ->where('tenant_id', $this->tenant->id)
        ->where('module_key', 'hrm')
        ->first();
    expect($row->status)->toBe(ModuleStatus::Disabled);
});

it('sync: 401 when called with no authenticated session', function (): void {
    $this->patchJson(
        "/api/v1/super-admin/tenants/{$this->tenant->id}/modules",
        ['modules' => [['module_key' => 'hrm', 'status' => 'disabled']]],
    )->assertStatus(401);
});

it('LOAD-BEARING: sync 404 when tenant_admin tries to access SA endpoint (Q8)', function (): void {
    $company = Company::factory()->forTenant($this->tenant)->create();
    $admin = User::factory()->forTenant($this->tenant)->create([
        'default_company_id' => $company->id,
        'current_company_id' => $company->id,
    ]);
    $admin->assignTenantRole($this->tenant, 'tenant_admin');

    $this->actingAs($admin);
    $response = $this->patchJson(
        "/api/v1/super-admin/tenants/{$this->tenant->id}/modules",
        ['modules' => [['module_key' => 'hrm', 'status' => 'disabled']]],
    );

    $response->assertStatus(404);
});

it('sync: 422 when module_key is not in the allowlist', function (): void {
    $this->actingAs($this->sa);
    $response = $this->patchJson(
        "/api/v1/super-admin/tenants/{$this->tenant->id}/modules",
        ['modules' => [['module_key' => 'gibberish', 'status' => 'active']]],
    );

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('modules.0.module_key');
});

it('sync: 422 when status is not in the ModuleStatus enum', function (): void {
    $this->actingAs($this->sa);
    $response = $this->patchJson(
        "/api/v1/super-admin/tenants/{$this->tenant->id}/modules",
        ['modules' => [['module_key' => 'hrm', 'status' => 'trial']]],
    );

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('modules.0.status');
});

it('sync: 422 when modules array is empty', function (): void {
    $this->actingAs($this->sa);
    $response = $this->patchJson(
        "/api/v1/super-admin/tenants/{$this->tenant->id}/modules",
        ['modules' => []],
    );

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('modules');
});

it('LOAD-BEARING: sync populates enabled_by_user_id with the SA who flipped (vs NULL on bootstrap)', function (): void {
    // The migration backfill uses NULL for enabled_by_user_id ("system
    // bootstrap"). UI-driven syncs MUST capture the actor — this is the
    // OTHER invariant for the nullable-but-populated rule. Pins them
    // together: bootstrap rows have NULL, UI rows have the actor's id.
    TenantModule::factory()->forTenant($this->tenant)->create();

    $this->actingAs($this->sa);
    $this->patchJson(
        "/api/v1/super-admin/tenants/{$this->tenant->id}/modules",
        ['modules' => [['module_key' => 'hrm', 'status' => 'active']]],
    )->assertOk();

    /** @var TenantModule $row */
    $row = TenantModule::query()
        ->where('tenant_id', $this->tenant->id)
        ->where('module_key', 'hrm')
        ->first();

    expect($row->enabled_by_user_id)->toBe($this->sa->id);
    expect($row->enabled_at)->not->toBeNull();
});

it('sync: creates a new row when none exists for the (tenant, module_key)', function (): void {
    $this->actingAs($this->sa);

    // No prior row; sync should INSERT one. SA actingAs activates the
    // TenantScope SA-bypass for the assertion query that follows.
    expect(TenantModule::query()->acrossTenants()->where('tenant_id', $this->tenant->id)->count())->toBe(0);

    $this->patchJson(
        "/api/v1/super-admin/tenants/{$this->tenant->id}/modules",
        ['modules' => [['module_key' => 'hrm', 'status' => 'active']]],
    )->assertOk();

    /** @var TenantModule $row */
    $row = TenantModule::query()
        ->acrossTenants()
        ->where('tenant_id', $this->tenant->id)
        ->where('module_key', 'hrm')
        ->first();
    expect($row)->not->toBeNull();
    expect($row->status)->toBe(ModuleStatus::Active);
    expect($row->enabled_by_user_id)->toBe($this->sa->id);
});

it('sync: restores a soft-deleted row when re-granting (preserves audit history)', function (): void {
    $row = TenantModule::factory()->forTenant($this->tenant)->create();
    $row->delete();

    expect($row->fresh()->trashed())->toBeTrue();

    $this->actingAs($this->sa);
    $this->patchJson(
        "/api/v1/super-admin/tenants/{$this->tenant->id}/modules",
        ['modules' => [['module_key' => 'hrm', 'status' => 'active']]],
    )->assertOk();

    // Same row id is restored — the audit-history chain on this entitlement
    // doesn't fragment into a new row.
    expect($row->fresh()->trashed())->toBeFalse();
    expect($row->fresh()->status)->toBe(ModuleStatus::Active);

    // Single non-deleted row exists for the (tenant, module) — partial
    // unique constraint is honoured.
    $count = TenantModule::query()
        ->where('tenant_id', $this->tenant->id)
        ->where('module_key', 'hrm')
        ->whereNull('deleted_at')
        ->count();
    expect($count)->toBe(1);
});

it('LOAD-BEARING: sync writes an audit_logs row (TenantModule has Auditable)', function (): void {
    $row = TenantModule::factory()->forTenant($this->tenant)->create();

    $this->actingAs($this->sa);
    $this->patchJson(
        "/api/v1/super-admin/tenants/{$this->tenant->id}/modules",
        ['modules' => [['module_key' => 'hrm', 'status' => 'disabled']]],
    )->assertOk();

    // Auditable writes to audit_logs on every create/update/delete.
    // The PATCH triggered an UPDATE (status flip), so an audit row exists.
    // Column is `action` (per the create_audit_logs migration), not `event`.
    $audit = DB::table('audit_logs')
        ->where('auditable_type', TenantModule::class)
        ->where('auditable_id', $row->id)
        ->where('action', 'updated')
        ->first();

    expect($audit)->not->toBeNull();
});
