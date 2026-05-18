<?php

declare(strict_types=1);

namespace Database\Seeders\Framework;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Default roles. These are GLOBAL roles (team_id=null) — reused across all
 * tenants. The USER↔ROLE assignment row (in model_has_roles) carries the
 * tenant-specific team_id; that's what scopes a role to a particular tenant.
 *
 * Why global roles instead of per-tenant role rows: the role definitions
 * (accountant has X+Y permissions) are uniform across tenants in our ERP
 * — every tenant's "accountant" should be able to view + create journal
 * entries, no exceptions. Per-tenant role customisation is a future
 * feature (would need a "custom_roles" UI), and if/when it lands it
 * coexists with these globals via Spatie's optional team_id on roles.
 *
 * Roles defined here:
 *   - tenant_admin: full access to settings + accounting basics
 *   - accountant:   journal entry view + create
 *   - viewer:       journal entry view only
 *
 * Run AFTER DefaultPermissionsSeeder.
 */
final class DefaultRolesSeeder extends Seeder
{
    public function run(): void
    {
        $registrar = app(PermissionRegistrar::class);
        // Global roles — explicitly clear team scoping before Role::firstOrCreate
        // (with teams=true, Spatie auto-fills team_id from the registrar otherwise).
        $registrar->setPermissionsTeamId(null);

        foreach ($this->rolesWithPermissions() as $name => $permissions) {
            $role = Role::findOrCreate($name);
            $role->syncPermissions($permissions);
        }

        $registrar->forgetCachedPermissions();
    }

    /**
     * Role → permission mapping. Permissions must already exist via
     * DefaultPermissionsSeeder.
     *
     * @return array<string, list<string>>
     */
    private function rolesWithPermissions(): array
    {
        return [
            'tenant_admin' => [
                'tenant.settings.manage',
                'accounting.journal_entry.view',
                'accounting.journal_entry.create',
                'hrm.employee.view',
                'hrm.employee.create',
                'hrm.employee.update',
                'hrm.employee.delete',
            ],
            'accountant' => [
                'accounting.journal_entry.view',
                'accounting.journal_entry.create',
            ],
            'viewer' => [
                'accounting.journal_entry.view',
                'hrm.employee.view',
            ],
        ];
    }
}
