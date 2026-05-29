<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────────
// TenantEntitlementServiceTest — covers the read-side service that
// EnforceModuleEntitlement, MeController, and (future) TenantModuleController
// all consume. Per §10.3: every consumer routes through this service; a
// future swap-to-cache becomes a single-file change.
//
// Filter logic must:
//   - return ['hrm'] when there's an Active, non-deleted row
//   - return [] when the only row is Disabled
//   - return [] when the only row is soft-deleted
//   - return [] when there's no row at all
// ─────────────────────────────────────────────────────────────────────────────

use App\Domain\Platform\Models\TenantModule;
use App\Domain\Platform\Services\TenantEntitlementService;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('entitledModuleKeysFor: returns active modules for the given tenant id', function (): void {
    $tenant = Tenant::factory()->withoutEntitlement()->create();
    TenantModule::factory()->forTenant($tenant)->create();

    /** @var TenantEntitlementService $svc */
    $svc = app(TenantEntitlementService::class);
    expect($svc->entitledModuleKeysFor($tenant->id))->toBe(['hrm']);
});

it('entitledModuleKeysFor: excludes Disabled rows', function (): void {
    $tenant = Tenant::factory()->withoutEntitlement()->create();
    TenantModule::factory()->forTenant($tenant)->disabled()->create();

    expect(app(TenantEntitlementService::class)->entitledModuleKeysFor($tenant->id))->toBe([]);
});

it('entitledModuleKeysFor: excludes soft-deleted rows', function (): void {
    $tenant = Tenant::factory()->withoutEntitlement()->create();
    $row = TenantModule::factory()->forTenant($tenant)->create();
    $row->delete();

    expect(app(TenantEntitlementService::class)->entitledModuleKeysFor($tenant->id))->toBe([]);
});

it('entitledModuleKeysFor: returns [] when no rows exist for the tenant', function (): void {
    $tenant = Tenant::factory()->withoutEntitlement()->create();
    expect(app(TenantEntitlementService::class)->entitledModuleKeysFor($tenant->id))->toBe([]);
});

it('entitledModuleKeysFor: returns [] when no tenant id is given and no tenant context is pinned', function (): void {
    // The service falls back to TenantContext::current()?->id when no
    // explicit id is provided. Without context (e.g. an SA's /auth/me),
    // it returns []. Empty array is the documented "no entitlement"
    // signal — SPA renders an empty launcher.
    expect(app(TenantEntitlementService::class)->entitledModuleKeysFor())->toBe([]);
});

it('isEntitled: returns true for Active row, false for Disabled or missing', function (): void {
    $entitled = Tenant::factory()->withoutEntitlement()->create();
    TenantModule::factory()->forTenant($entitled)->create();

    $disabled = Tenant::factory()->withoutEntitlement()->create();
    TenantModule::factory()->forTenant($disabled)->disabled()->create();

    $missing = Tenant::factory()->withoutEntitlement()->create();

    $svc = app(TenantEntitlementService::class);
    expect($svc->isEntitled($entitled->id, 'hrm'))->toBeTrue();
    expect($svc->isEntitled($disabled->id, 'hrm'))->toBeFalse();
    expect($svc->isEntitled($missing->id, 'hrm'))->toBeFalse();
});
