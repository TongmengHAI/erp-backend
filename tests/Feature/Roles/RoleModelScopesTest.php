<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────────
// RoleModelScopesTest — Phase 2B Session 1.
//
// Pins the three scopes on the extended Role model: system(),
// custom(), forTenant($tenantId). These are the primitives every
// Session 2 query routes through; correctness here is load-bearing
// for the entire CRUD surface.
//
// Default scope behavior (SoftDeletes exclusion) is also asserted —
// a soft-deleted custom role is excluded from scopeCustom() and
// scopeForTenant() by default, surfaceable via withTrashed().
// ─────────────────────────────────────────────────────────────────────────────

use App\Domain\Identity\Models\Role;
use Database\Seeders\Framework\DefaultPermissionsSeeder;
use Database\Seeders\Framework\DefaultRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(DefaultPermissionsSeeder::class);
    $this->seed(DefaultRolesSeeder::class);
});

it('scopeSystem returns only the seeded system rows', function (): void {
    $rows = Role::system()->get();
    expect($rows)->toHaveCount(3);
    expect($rows->pluck('name')->sort()->values()->all())->toBe(['accountant', 'tenant_admin', 'viewer']);
    $rows->each(fn (Role $r) => expect($r->is_system)->toBeTrue());
    $rows->each(fn (Role $r) => expect($r->team_id)->toBeNull());
});

it('scopeCustom returns only custom rows; empty when none exist', function (): void {
    expect(Role::custom()->count())->toBe(0);

    app(PermissionRegistrar::class)->setPermissionsTeamId(1);
    Role::create([
        'name' => 'Custom A',
        'guard_name' => 'web',
        'team_id' => 1,
        'is_system' => false,
    ]);
    Role::create([
        'name' => 'Custom B',
        'guard_name' => 'web',
        'team_id' => 2,
        'is_system' => false,
    ]);

    $custom = Role::custom()->get();
    expect($custom)->toHaveCount(2);
    $custom->each(fn (Role $r) => expect($r->is_system)->toBeFalse());
});

it('scopeForTenant returns system rows PLUS the tenant custom rows; excludes other tenants custom rows', function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    Role::create([
        'name' => 'Tenant 1 Custom',
        'guard_name' => 'web',
        'team_id' => 1,
        'is_system' => false,
    ]);
    Role::create([
        'name' => 'Tenant 2 Custom',
        'guard_name' => 'web',
        'team_id' => 2,
        'is_system' => false,
    ]);

    $forTenant1 = Role::forTenant(1)->get();

    // System rows (3) + tenant 1's custom (1) = 4. Tenant 2's row absent.
    expect($forTenant1)->toHaveCount(4);
    expect($forTenant1->pluck('name')->all())->toContain('Tenant 1 Custom');
    expect($forTenant1->pluck('name')->all())->not->toContain('Tenant 2 Custom');
});

it('a soft-deleted custom role is excluded from scopeCustom() but surfaces via withTrashed()', function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(1);
    $role = Role::create([
        'name' => 'Doomed',
        'guard_name' => 'web',
        'team_id' => 1,
        'is_system' => false,
    ]);
    $role->delete();

    expect(Role::custom()->count())->toBe(0);
    expect(Role::custom()->withTrashed()->count())->toBe(1);
    expect(Role::custom()->withTrashed()->first()->trashed())->toBeTrue();
});
