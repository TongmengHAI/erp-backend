<?php

declare(strict_types=1);

use App\Domain\Identity\Models\Role;
use Database\Seeders\Framework\DefaultRolesSeeder;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Phase 2B — Session 1: per-deploy sync of system role permission sets
 * to the live registry.
 *
 * ╔══════════════════════════════════════════════════════════════════════╗
 * ║  THIS MIGRATION IS INTENTIONALLY IDEMPOTENT AND RE-RUNNABLE.         ║
 * ║                                                                      ║
 * ║  It is NOT a one-time data migration. It is the "ensure system       ║
 * ║  roles have correct permission sets on every deploy" invariant       ║
 * ║  carrier. Re-running it produces no state change when permissions    ║
 * ║  already match the registry.                                         ║
 * ║                                                                      ║
 * ║  Behavior per system role:                                           ║
 * ║                                                                      ║
 * ║    tenant_admin → syncPermissions(Permission::all())                 ║
 * ║      The "auto-grant" intent. When a new module ships, its           ║
 * ║      permissions land in the registry via the seeder (which the      ║
 * ║      module's own migration must arrange to run, or the              ║
 * ║      module's migration appends to DefaultPermissionsSeeder + ships  ║
 * ║      its own permission-creation migration). On the next deploy,     ║
 * ║      this migration runs and tenant_admin automatically gets them.   ║
 * ║      No human seeder edit required.                                  ║
 * ║                                                                      ║
 * ║    accountant → syncPermissions(DefaultRolesSeeder::                 ║
 * ║                                  accountantPermissions())            ║
 * ║    viewer     → syncPermissions(DefaultRolesSeeder::                 ║
 * ║                                  viewerPermissions())                ║
 * ║      The "explicit grant" intent. Their scopes do not grow           ║
 * ║      automatically; the seeder's static method is the single         ║
 * ║      source of truth for what they should have. When scope must      ║
 * ║      change, update the seeder's static method AND add a NEW         ║
 * ║      migration (do NOT edit this migration — it has shipped).        ║
 * ║                                                                      ║
 * ║      The seeder + this migration both read the same static methods,  ║
 * ║      so the fresh-install path and the upgrade path produce          ║
 * ║      identical state. SystemRolePermissionSyncMigrationTest pins     ║
 * ║      this convergence.                                               ║
 * ║                                                                      ║
 * ║  FUTURE-MIGRATION NAMING:                                            ║
 * ║                                                                      ║
 * ║    When accountant or viewer scope changes, the new migration uses   ║
 * ║    standard Laravel timestamp-prefixed naming, e.g.                  ║
 * ║      2026_09_15_100000_update_accountant_role_permissions.php        ║
 * ║    Convention is "newest migration wins" — readers see the timeline  ║
 * ║    naturally. NEVER edit this migration after it has shipped.        ║
 * ║                                                                      ║
 * ║  CUSTOM ROLES UNTOUCHED.                                             ║
 * ║                                                                      ║
 * ║    The Role::system() scope filters on is_system=true; custom rows   ║
 * ║    don't match. A LOAD-BEARING test creates a custom role with       ║
 * ║    explicit permissions, re-runs the migration, and asserts the      ║
 * ║    custom role's permission set is untouched.                        ║
 * ║                                                                      ║
 * ║  PRE-MIGRATION GUARD.                                                ║
 * ║                                                                      ║
 * ║    Calling Role::system()->where(...)->first() returns null if the   ║
 * ║    seeder hasn't run yet (e.g. on a totally fresh DB where           ║
 * ║    DefaultRolesSeeder is queued to run after migrations). The        ║
 * ║    migration silently no-ops in that case — the seeder's fresh-      ║
 * ║    install path will set permissions when it runs. No throw; the     ║
 * ║    migration just exits cleanly.                                     ║
 * ╚══════════════════════════════════════════════════════════════════════╝
 *
 * down(): no-op. Forward-only per CLAUDE.md §3 — undoing the sync
 * would put system roles in an undefined permission state.
 */
return new class extends Migration
{
    public function up(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId(null);

        /** @var list<string> $allPermissionNames */
        $allPermissionNames = Permission::pluck('name')->values()->all();

        $this->syncIfPresent(
            DefaultRolesSeeder::SYSTEM_ROLE_NAME_TENANT_ADMIN,
            $allPermissionNames
        );
        $this->syncIfPresent(
            DefaultRolesSeeder::SYSTEM_ROLE_NAME_ACCOUNTANT,
            DefaultRolesSeeder::accountantPermissions()
        );
        $this->syncIfPresent(
            DefaultRolesSeeder::SYSTEM_ROLE_NAME_VIEWER,
            DefaultRolesSeeder::viewerPermissions()
        );

        $registrar->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Forward-only — undoing would put system roles in an
        // undefined permission state. Per CLAUDE.md §3.
    }

    /**
     * Sync permissions for the named system role, but ONLY if the row
     * exists. Returns silently on fresh DBs where the seeder hasn't
     * run yet — the seeder will set permissions when it runs.
     *
     * @param  list<string>  $permissionNames
     */
    private function syncIfPresent(string $roleName, array $permissionNames): void
    {
        $role = Role::system()->where('name', $roleName)->where('guard_name', 'web')->first();
        if ($role === null) {
            return;
        }
        $role->syncPermissions($permissionNames);
    }
};
