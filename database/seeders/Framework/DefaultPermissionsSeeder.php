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
            // hrm.leave_request — five permissions, not four. .approve is
            // separate from .update because they represent different
            // authority kinds: .update is "edit pending content," .approve
            // is "decision-making authority" (gates both /approve and
            // /reject — a manager has decision authority, not approval-only
            // authority). Splitting them means a tenant_admin who wants to
            // delegate decision-making without granting full edit rights
            // can do so on Day 1 (assign .view+.approve to a "manager"
            // role) without us needing a Day-2 permission split that
            // breaks existing role assignments.
            'hrm.leave_request.view',
            'hrm.leave_request.create',
            'hrm.leave_request.update',
            'hrm.leave_request.delete',
            'hrm.leave_request.approve',
            // hrm.attendance — straight 4-perm CRUD. No transition or
            // decision-authority split (attendance is admin-entered
            // records, not a workflow). Same default-role mapping as
            // Employee/Department: tenant_admin gets all four, viewer
            // gets .view only.
            'hrm.attendance.view',
            'hrm.attendance.create',
            'hrm.attendance.update',
            'hrm.attendance.delete',
            // hrm.position — straight 4-perm CRUD. Same default-role
            // mapping as Department/Attendance: tenant_admin gets all
            // four, viewer gets .view only.
            'hrm.position.view',
            'hrm.position.create',
            'hrm.position.update',
            'hrm.position.delete',
        ];
    }
}
