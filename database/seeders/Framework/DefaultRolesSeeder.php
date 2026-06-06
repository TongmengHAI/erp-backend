<?php

declare(strict_types=1);

namespace Database\Seeders\Framework;

use App\Domain\Identity\Models\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * ╔══════════════════════════════════════════════════════════════════════╗
 * ║  BOOTSTRAP responsibility — this seeder runs on FRESH installs only. ║
 * ║                                                                      ║
 * ║  Creates the three system role rows (tenant_admin, accountant,       ║
 * ║  viewer) with is_system=true if they don't yet exist. Idempotent     ║
 * ║  via Role::firstOrCreate — safe to re-run, but it does NOT update    ║
 * ║  existing rows' permission sets after the first run.                 ║
 * ║                                                                      ║
 * ║  PER-DEPLOY sync of permission sets to the live registry lives in:   ║
 * ║                                                                      ║
 * ║    database/migrations/                                              ║
 * ║      2026_06_06_100200_ensure_system_role_permissions_match_         ║
 * ║      registry.php                                                    ║
 * ║                                                                      ║
 * ║  The split exists because system role permission sets need to stay   ║
 * ║  synced as the permission registry grows (a new module ships → new   ║
 * ║  permissions → tenant_admin auto-grants them). Seeders run on every  ║
 * ║  deploy ONLY if the deploy script chooses to. Migrations run on      ║
 * ║  every deploy regardless. Putting the per-deploy sync invariant in   ║
 * ║  a migration removes the deploy-flow coordination dependency.        ║
 * ║                                                                      ║
 * ║  The two files share their permission-set definitions via the        ║
 * ║  constants below (SYSTEM_ROLE_NAME_* + accountantPermissions() +     ║
 * ║  viewerPermissions()). The sync migration imports them so the two    ║
 * ║  layers agree by construction, not by convention.                    ║
 * ║                                                                      ║
 * ║  Custom roles (is_system=false) are NEVER touched by this seeder OR  ║
 * ║  by the sync migration. Both syncers operate by name + is_system=    ║
 * ║  true filter — custom rows don't match either condition.             ║
 * ║                                                                      ║
 * ║  NAMING CONVENTION (established by Phase 2B Session 1):              ║
 * ║                                                                      ║
 * ║    Default<Subject>Seeder           — bootstrap. Create-if-missing.  ║
 * ║                                       Idempotent but doesn't update. ║
 * ║                                                                      ║
 * ║    Ensure<Subject>Match<Source>     — sync invariant. Re-establishes ║
 * ║      (Laravel migration)              exact state every run.         ║
 * ║                                                                      ║
 * ║  Pre-flight confirmed no prior precedent for this split in the      ║
 * ║  codebase (Phase 2A Sessions 1-5 didn't introduce one). Future      ║
 * ║  splits follow the same convention + dual-docblock pattern.         ║
 * ╚══════════════════════════════════════════════════════════════════════╝
 *
 * Permission naming pattern is documented in DefaultPermissionsSeeder.
 *
 * Run AFTER DefaultPermissionsSeeder.
 */
final class DefaultRolesSeeder extends Seeder
{
    public const SYSTEM_ROLE_NAME_TENANT_ADMIN = 'tenant_admin';

    public const SYSTEM_ROLE_NAME_ACCOUNTANT = 'accountant';

    public const SYSTEM_ROLE_NAME_VIEWER = 'viewer';

    /** @return list<string> */
    public static function accountantPermissions(): array
    {
        return [
            'accounting.journal_entry.view',
            'accounting.journal_entry.create',
        ];
    }

    /** @return list<string> */
    public static function viewerPermissions(): array
    {
        return [
            'accounting.journal_entry.view',
            'hrm.employee.view',
            'hrm.department.view',
            // viewer can see leave requests but cannot create, edit,
            // delete, or decide. Read-only is the right grant level
            // for an auditor or finance read-out persona.
            'hrm.leave_request.view',
            'hrm.attendance.view',
            'hrm.position.view',
            'hrm.branch.view',
            'hrm.leave_balance.view',
            // viewer can SEE settings (auditors need to understand
            // how the system is configured) but cannot modify.
            'settings.hrm.view',
            // roles.* — viewer can see role definitions for the same
            // audit reason (understanding how the system is
            // configured). No assign / no edit / no delete.
            'roles.view',
        ];
    }

    public function run(): void
    {
        $registrar = app(PermissionRegistrar::class);
        // Global roles — explicitly clear team scoping before firstOrCreate
        // (with teams=true, Spatie auto-fills team_id from the registrar otherwise).
        $registrar->setPermissionsTeamId(null);

        // System role rows. is_system=true is set on creation; if the
        // row already exists (re-running the seeder), the flag is left
        // alone — the sibling backfill migration is the authority for
        // existing rows.
        foreach (
            [
                self::SYSTEM_ROLE_NAME_TENANT_ADMIN,
                self::SYSTEM_ROLE_NAME_ACCOUNTANT,
                self::SYSTEM_ROLE_NAME_VIEWER,
            ] as $name
        ) {
            Role::firstOrCreate(
                ['name' => $name, 'guard_name' => 'web'],
                ['is_system' => true]
            );
        }

        // Initial permission assignment for the FRESH-INSTALL path.
        // Re-running this seeder will OVERWRITE permission sets each
        // run (syncPermissions resets). That's intentional for the
        // bootstrap case — the seeder + the per-deploy migration both
        // converge on the same state. The sync migration is the
        // authority for the per-deploy upgrade path; this seeder is
        // the authority for the fresh-install path. Both produce
        // identical state. A LOAD-BEARING test (SystemRolePermission
        // SyncMigrationTest) asserts this convergence.
        $tenantAdmin = Role::system()->where('name', self::SYSTEM_ROLE_NAME_TENANT_ADMIN)->firstOrFail();
        $tenantAdmin->syncPermissions(Permission::pluck('name')->all());

        $accountant = Role::system()->where('name', self::SYSTEM_ROLE_NAME_ACCOUNTANT)->firstOrFail();
        $accountant->syncPermissions(self::accountantPermissions());

        $viewer = Role::system()->where('name', self::SYSTEM_ROLE_NAME_VIEWER)->firstOrFail();
        $viewer->syncPermissions(self::viewerPermissions());

        $registrar->forgetCachedPermissions();
    }
}
