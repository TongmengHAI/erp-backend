<?php

declare(strict_types=1);

namespace App\Support\Tenancy\Scopes;

use App\Models\User;
use App\Support\Tenancy\Exceptions\TenantContextMissingException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Global Eloquent scope that injects `WHERE tenant_id = :current_tenant_id`
 * on every query against a model using the BelongsToTenant trait.
 *
 * Behaviour:
 *  - inside TenantContext::asSystem() → no scope applied (intentional bypass)
 *  - authenticated super-admin user   → no scope applied (vendor-side bypass)
 *  - no tenant set otherwise          → throws TenantContextMissingException (fail loud per §G)
 *  - tenant set                       → adds the WHERE clause, qualified by the model's table
 */
final class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if ($context->inSystemMode()) {
            return;
        }

        // Super-admin bypass. Mirrors the inSystemMode() short-circuit
        // above. SA users have no tenant_id by composite DB CHECK; they
        // legitimately see rows across all tenants for platform-side
        // operations (tenant CRUD, dashboard metrics). The auth facade
        // is safe in CLI / queue contexts — returns null when no session
        // and falls through to the existing throw (which is correct:
        // unauthenticated code paths must either set TenantContext or
        // use asSystem(), the same rule that held before this bypass).
        $authUser = Auth::user();
        if ($authUser instanceof User && $authUser->isSuperAdmin()) {
            return;
        }

        if ($context->current() === null) {
            throw new TenantContextMissingException(
                sprintf('Querying %s without a resolved tenant.', $model::class)
            );
        }

        $builder->where(
            $model->qualifyColumn('tenant_id'),
            $context->currentId()
        );
    }
}
