<?php

declare(strict_types=1);

namespace App\Domain\Platform\Actions;

use App\Models\Company;
use App\Models\Tenant;
use App\Models\User;

/**
 * Result of CreateTenantWithInitialAdminAction::execute().
 *
 * The `initialAdminPassword` field is the load-bearing one — it carries
 * the plaintext password from the action to the controller (and onward
 * to the response body for ONE-TIME display). It never lands in audit
 * logs, application logs, or any persistent store; the only sink that
 * sees it is the HTTP response body.
 *
 * Read-only by construction (public readonly) so accidentally writing
 * to one of these fields downstream is a compile error.
 */
final readonly class TenantCreationResult
{
    public function __construct(
        public Tenant $tenant,
        public Company $company,
        public User $admin,
        public string $initialAdminPassword,
    ) {}
}
