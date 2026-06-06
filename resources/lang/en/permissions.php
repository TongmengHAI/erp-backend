<?php

declare(strict_types=1);

/**
 * Phase 2B — permission descriptions catalog (English).
 *
 * Backend i18n. The single source of truth for human-readable
 * permission and domain labels surfaced to the SPA via
 * GET /api/v1/permissions/descriptions.
 *
 * Shape:
 *   - domains.{domain_key} = "Display label"
 *       Used for domain-grouping headers in the PermissionPicker
 *       (Session 4) and the read-only PermissionList (Session 4).
 *
 *   - permissions.{full_permission_name} = "Human-readable label"
 *       One entry per permission in DefaultPermissionsSeeder. When a
 *       new permission ships, add its entry here. A LOAD-BEARING test
 *       in Session 2 (PermissionDescriptionCoverageTest) will assert
 *       every registered permission has a description; the
 *       endpoint's 200 response shape includes both maps.
 *
 * Future Khmer migration: add resources/lang/km/permissions.php with
 * the same key shape. Per-user locale routing lands when User gains
 * a locale column (see User model @todo). Until then every response
 * is English.
 *
 * Labels follow these style rules:
 *   - Sentence case. "View employees", not "View Employees".
 *   - Plural for resource names. "View employees", not "View employee".
 *   - Verb first. "Approve leave requests", not "Leave request approval".
 *   - Avoid technical jargon. "View people" would be wrong — the user
 *     speaks "employees" in HRM context; consistency with menu labels
 *     matters more than wider-audience accessibility for an internal
 *     ERP tool.
 *   - Avoid duplicating the domain prefix. The domain label is rendered
 *     above the permissions; "Employees → View employees" is correct
 *     UX, "HRM → HRM employees view" is not.
 */
return [
    'domains' => [
        'tenant' => 'Tenant',
        'accounting' => 'Accounting',
        'hrm' => 'HRM',
        'settings' => 'Settings',
        'users' => 'Users',
        'roles' => 'Roles',
    ],

    'permissions' => [
        // tenant.*
        'tenant.settings.manage' => 'Manage tenant settings',

        // accounting.*
        'accounting.journal_entry.view' => 'View journal entries',
        'accounting.journal_entry.create' => 'Create journal entries',

        // hrm.employee.*
        'hrm.employee.view' => 'View employees',
        'hrm.employee.create' => 'Create employees',
        'hrm.employee.update' => 'Update employees',
        'hrm.employee.delete' => 'Delete employees',

        // hrm.department.*
        'hrm.department.view' => 'View departments',
        'hrm.department.create' => 'Create departments',
        'hrm.department.update' => 'Update departments',
        'hrm.department.delete' => 'Delete departments',

        // hrm.leave_request.*
        'hrm.leave_request.view' => 'View leave requests',
        'hrm.leave_request.create' => 'Create leave requests',
        'hrm.leave_request.update' => 'Update leave requests',
        'hrm.leave_request.delete' => 'Delete leave requests',
        'hrm.leave_request.approve' => 'Approve or reject leave requests',

        // hrm.attendance.*
        'hrm.attendance.view' => 'View attendance records',
        'hrm.attendance.create' => 'Create attendance records',
        'hrm.attendance.update' => 'Update attendance records',
        'hrm.attendance.delete' => 'Delete attendance records',

        // hrm.position.*
        'hrm.position.view' => 'View positions',
        'hrm.position.create' => 'Create positions',
        'hrm.position.update' => 'Update positions',
        'hrm.position.delete' => 'Delete positions',

        // hrm.branch.*
        'hrm.branch.view' => 'View branches',
        'hrm.branch.create' => 'Create branches',
        'hrm.branch.update' => 'Update branches',
        'hrm.branch.delete' => 'Delete branches',

        // hrm.leave_balance.*
        'hrm.leave_balance.view' => 'View leave balances',
        'hrm.leave_balance.create' => 'Create leave balances',
        'hrm.leave_balance.update' => 'Update leave balances',
        'hrm.leave_balance.delete' => 'Delete leave balances',

        // settings.hrm.*
        'settings.hrm.view' => 'View HRM settings',
        'settings.hrm.update' => 'Update HRM settings',

        // users.*
        'users.view' => 'View users',
        'users.invite' => 'Invite users',
        'users.update' => 'Update user details',
        'users.disable' => 'Disable users',
        'users.deactivate' => 'Deactivate users',

        // roles.*
        'roles.view' => 'View roles',
        'roles.create' => 'Create custom roles',
        'roles.update' => 'Update custom roles',
        'roles.delete' => 'Delete custom roles',
        'roles.assign' => 'Assign roles to users',
    ],
];
