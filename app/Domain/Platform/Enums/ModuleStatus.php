<?php

declare(strict_types=1);

namespace App\Domain\Platform\Enums;

/**
 * Entitlement state for a (tenant, module) pair on the tenant_modules table.
 *
 *   Active   — module is enabled; tenant users with the right Spatie
 *              permissions reach the module's endpoints normally.
 *   Disabled — module is registered for this tenant (so audit history
 *              survives) but blocked. Routes under the module's prefix
 *              return 403 module_not_entitled until a Super Admin
 *              re-enables.
 *
 * Backed by varchar(16) with a CHECK constraint
 * (tenant_modules_status_enum_check, see the create migration).
 *
 * Future statuses (Trial, ModuleSuspended) are billing concerns deferred
 * per the explicit cuts for v1.
 */
enum ModuleStatus: string
{
    case Active = 'active';
    case Disabled = 'disabled';
}
