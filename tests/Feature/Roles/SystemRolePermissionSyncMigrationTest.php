<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────────
// SystemRolePermissionSyncMigrationTest — Phase 2B Session 1.
//
// Pins the per-deploy sync invariant carried by the
// 2026_06_06_100200_ensure_system_role_permissions_match_registry
// migration. Four LOAD-BEARING tests:
//
//   1. Re-running the migration is idempotent (state unchanged).
//   2. Adding a new permission to the registry → tenant_admin
//      auto-grants it on the next migration run.
//   3. Adding a new permission to the registry → accountant + viewer
//      are NOT modified.
//   4. Creating a custom role with explicit permissions → re-running
//      the migration does NOT touch the custom role.
//
// The migration is exercised via rerunEnsureSystemRolePermissions
// Migration() — see the helper below for rationale. Direct execution
// of the migration's up() bypasses Laravel's migrator (which would
// skip the file as already-run on a RefreshDatabase test DB).
// ─────────────────────────────────────────────────────────────────────────────

use App\Domain\Identity\Models\Role;
use Database\Seeders\Framework\DefaultPermissionsSeeder;
use Database\Seeders\Framework\DefaultRolesSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // The fresh-install state. We don't use $this->seed() (the project's
    // DatabaseSeeder also creates a SuperAdmin which is irrelevant here
    // and adds noise) — explicit seeds keep the test surface small.
    $this->seed(DefaultPermissionsSeeder::class);
    $this->seed(DefaultRolesSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/**
 * Re-execute the EnsureSystemRolePermissionsMatchRegistry migration's
 * up() directly. Bypasses Laravel's migrator (which would skip the
 * file as already-run on a RefreshDatabase test DB). The migration is
 * designed to be re-runnable; this helper exercises that path. The
 * file returns an anonymous Migration instance via the `return new
 * class extends Migration { ... };` idiom — we capture it via require
 * (each require evaluates the file fresh and returns the anonymous-class
 * instance).
 */
function rerunEnsureSystemRolePermissionsMigration(): void
{
    /** @var Migration $migration */
    $migration = require database_path(
        'migrations/2026_06_06_100200_ensure_system_role_permissions_match_registry.php'
    );
    $migration->up();
}

it('LOAD-BEARING: re-running the sync migration is idempotent — state unchanged', function (): void {
    /** @var Role $tenantAdmin */
    $tenantAdmin = Role::system()->where('name', 'tenant_admin')->firstOrFail();
    $beforeTenantAdmin = $tenantAdmin->permissions->pluck('name')->sort()->values()->all();

    /** @var Role $accountant */
    $accountant = Role::system()->where('name', 'accountant')->firstOrFail();
    $beforeAccountant = $accountant->permissions->pluck('name')->sort()->values()->all();

    /** @var Role $viewer */
    $viewer = Role::system()->where('name', 'viewer')->firstOrFail();
    $beforeViewer = $viewer->permissions->pluck('name')->sort()->values()->all();

    // Re-run the specific migration by path. --force allowed in prod.
    rerunEnsureSystemRolePermissionsMigration();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $tenantAdmin->load('permissions');
    $accountant->load('permissions');
    $viewer->load('permissions');

    expect($tenantAdmin->permissions->pluck('name')->sort()->values()->all())->toBe($beforeTenantAdmin);
    expect($accountant->permissions->pluck('name')->sort()->values()->all())->toBe($beforeAccountant);
    expect($viewer->permissions->pluck('name')->sort()->values()->all())->toBe($beforeViewer);
});

it('LOAD-BEARING: a new permission added to the registry is auto-granted to tenant_admin on next sync', function (): void {
    // Simulate a new module's migration adding a permission to the
    // registry after the initial seed.
    Permission::create(['name' => 'fake_module.thing.view', 'guard_name' => 'web']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    /** @var Role $tenantAdmin */
    $tenantAdmin = Role::system()->where('name', 'tenant_admin')->firstOrFail();
    expect($tenantAdmin->permissions->pluck('name')->all())->not->toContain('fake_module.thing.view');

    rerunEnsureSystemRolePermissionsMigration();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $tenantAdmin->load('permissions');

    expect($tenantAdmin->permissions->pluck('name')->all())->toContain('fake_module.thing.view');
});

it('LOAD-BEARING: a new permission added to the registry is NOT granted to accountant or viewer', function (): void {
    Permission::create(['name' => 'fake_module.thing.view', 'guard_name' => 'web']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    rerunEnsureSystemRolePermissionsMigration();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    /** @var Role $accountant */
    $accountant = Role::system()->where('name', 'accountant')->firstOrFail();
    expect($accountant->permissions->pluck('name')->all())->not->toContain('fake_module.thing.view');

    /** @var Role $viewer */
    $viewer = Role::system()->where('name', 'viewer')->firstOrFail();
    expect($viewer->permissions->pluck('name')->all())->not->toContain('fake_module.thing.view');
});

it('LOAD-BEARING: custom-role rows are untouched by re-running the sync migration', function (): void {
    // Create a custom role with an explicit subset of permissions.
    app(PermissionRegistrar::class)->setPermissionsTeamId(1);
    /** @var Role $custom */
    $custom = Role::create([
        'name' => 'Senior Accountant',
        'guard_name' => 'web',
        'team_id' => 1,
        'is_system' => false,
        'description' => 'Custom test role',
    ]);
    $custom->syncPermissions([
        'accounting.journal_entry.view',
        'accounting.journal_entry.create',
    ]);
    $beforeIds = $custom->permissions->pluck('id')->sort()->values()->all();

    // Run the sync migration.
    rerunEnsureSystemRolePermissionsMigration();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $custom->load('permissions');
    expect($custom->permissions->pluck('id')->sort()->values()->all())->toBe($beforeIds);
    expect($custom->is_system)->toBeFalse();
    expect($custom->description)->toBe('Custom test role');
});

it('the fresh-install path (seeder) and upgrade path (migration) converge on identical state', function (): void {
    // Capture the post-seed state — this IS the fresh-install state.
    /** @var Role $tenantAdmin */
    $tenantAdmin = Role::system()->where('name', 'tenant_admin')->firstOrFail();
    $seederState = $tenantAdmin->permissions->pluck('name')->sort()->values()->all();

    // Mutate the role's permissions to simulate drift, then run the
    // migration to re-sync.
    $tenantAdmin->syncPermissions(['users.view']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    expect($tenantAdmin->fresh()->load('permissions')->permissions->pluck('name')->all())->toBe(['users.view']);

    rerunEnsureSystemRolePermissionsMigration();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $tenantAdmin->load('permissions');
    expect($tenantAdmin->permissions->pluck('name')->sort()->values()->all())->toBe($seederState);
});
