<?php

declare(strict_types=1);

namespace App\Support\Identity\Enums;

/**
 * Identity discriminator for the users table.
 *
 *   TenantUser  — normal user belonging to a tenant. Has tenant_id NOT NULL;
 *                 participates in TenantScope, ResolveTenant, ResolveCompany,
 *                 and Spatie role/permission scoping.
 *   SuperAdmin  — vendor-side platform operator. NO tenant/company FKs.
 *                 Bypasses TenantScope + ResolveTenant + ResolveCompany.
 *                 Implicit access to all super-admin endpoints (no Spatie
 *                 permissions needed; gate is the user-type flag alone).
 *
 * Backed by varchar(16) in the DB with a CHECK constraint mirroring these
 * values (see 2026_06_04_100000_add_type_to_users_table.php).
 */
enum UserType: string
{
    case TenantUser = 'tenant_user';
    case SuperAdmin = 'super_admin';
}
