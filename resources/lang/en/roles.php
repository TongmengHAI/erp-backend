<?php

declare(strict_types=1);

/**
 * Phase 2B — system role labels + descriptions (English).
 *
 * Used by:
 *   - The /admin/roles SPA pages, surfaced via /api/v1/admin/roles
 *     (Session 2) which calls __() lookups when serializing system
 *     rows.
 *   - The role-options endpoint when surfacing system rows in the
 *     invite + edit forms (Session 5 if not already in Session 2).
 *
 * Custom role labels and descriptions are admin-entered text on the
 * row itself (roles.description column). Only system rows look up
 * their text here.
 *
 * Future Khmer migration: add resources/lang/km/roles.php with the
 * same key shape.
 */
return [
    'system' => [
        'tenant_admin' => [
            'label' => 'Tenant Administrator',
            'description' => 'Full access within the tenant. Manages users, roles, settings, and every business module. New permissions are auto-granted to this role on deploy.',
        ],
        'accountant' => [
            'label' => 'Accountant',
            'description' => 'Can view and create journal entries. Scope is intentionally narrow — additional permissions require an explicit grant via a custom role.',
        ],
        'viewer' => [
            'label' => 'Viewer',
            'description' => 'Read-only access across HRM, accounting, and settings. Suitable for auditors and finance read-out personas.',
        ],
    ],
];
