<?php

declare(strict_types=1);

namespace App\Support\Company\Enums;

/**
 * Company lifecycle state.
 *
 * Deliberately no Suspended — suspension is a tenant-level concept (handled
 * by App\Support\Tenancy\Enums\TenantStatus). A tenant being suspended
 * blocks access to every company in it. CompanyStatus models the
 * within-tenant lifecycle of a legal entity (e.g., a subsidiary wound up
 * while the holding company continues operating).
 */
enum CompanyStatus: string
{
    case Active = 'active';
    case Archived = 'archived';
}
