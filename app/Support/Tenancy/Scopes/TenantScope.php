<?php

declare(strict_types=1);

namespace App\Support\Tenancy\Scopes;

use App\Support\Tenancy\Exceptions\TenantContextMissingException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Global Eloquent scope that injects `WHERE tenant_id = :current_tenant_id`
 * on every query against a model using the BelongsToTenant trait.
 *
 * Behaviour:
 *  - inside TenantContext::asSystem() → no scope applied (intentional bypass)
 *  - no tenant set otherwise           → throws TenantContextMissingException (fail loud per §G)
 *  - tenant set                         → adds the WHERE clause, qualified by the model's table
 */
final class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if ($context->inSystemMode()) {
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
