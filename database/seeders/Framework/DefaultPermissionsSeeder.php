<?php

declare(strict_types=1);

namespace Database\Seeders\Framework;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * ──────────────────────────────────────────────────────────────────────────
 * Permission naming pattern — MUST be followed for every new permission.
 *
 *   {domain}.{resource}.{action}
 *
 *   domain:   tenant | accounting | hrm | inventory | procurement | sales
 *   resource: snake_case noun (journal_entry, chart_of_account, payroll_run, …)
 *   action:   view | create | update | delete | manage | <verb_noun for non-CRUD>
 *
 * Examples:
 *   accounting.journal_entry.view
 *   accounting.period.close
 *   hrm.employee.invite
 *
 * Never use:
 *   accounting.view-journals          (action position contains the resource)
 *   manage-accounting                  (no dotted segments)
 *   AccountingJournalEntryView         (PascalCase, wrong delimiter)
 *
 * When adding a new permission:
 *   1. Add it to this seeder's permissions() list.
 *   2. Document it in backend/docs/api/v1/<domain>.md under
 *      "Permissions reference".
 *   3. Assign it to the appropriate role(s) in DefaultRolesSeeder.
 *
 * Permissions are global (team_id is null on the permissions table).
 * Roles can be global OR per-tenant; default roles are global. The
 * USER↔ROLE assignment carries team_id = tenant_id.
 * ──────────────────────────────────────────────────────────────────────────
 */
final class DefaultPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $registrar = app(PermissionRegistrar::class);
        // Permissions are global; null clears any inherited team scoping.
        $registrar->setPermissionsTeamId(null);

        foreach ($this->permissions() as $name) {
            Permission::findOrCreate($name);
        }

        $registrar->forgetCachedPermissions();
    }

    /**
     * The full catalog of permissions known to the system.
     * Slice 5 ships the minimum to make /me's permissions array observable;
     * later business domains append to this list as they land.
     *
     * @return list<string>
     */
    private function permissions(): array
    {
        return [
            // tenant.*
            'tenant.settings.manage',

            // accounting.*
            'accounting.journal_entry.view',
            'accounting.journal_entry.create',

            // hrm.*
            'hrm.employee.view',
            'hrm.employee.create',
            'hrm.employee.update',
            'hrm.employee.delete',
            'hrm.department.view',
            'hrm.department.create',
            'hrm.department.update',
            'hrm.department.delete',
        ];
    }
}
