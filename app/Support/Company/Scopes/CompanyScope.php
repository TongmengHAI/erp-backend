<?php

declare(strict_types=1);

namespace App\Support\Company\Scopes;

use App\Models\User;
use App\Support\Company\CompanyContext;
use App\Support\Company\Exceptions\CompanyContextMissingException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Global Eloquent scope that injects `WHERE company_id = :current_company_id`
 * on every query against a model using the BelongsToCompany trait. Mirrors
 * TenantScope one layer down — both filters apply when a model uses both
 * traits (the order doesn't matter; both WHEREs are AND-joined).
 *
 * Behaviour:
 *  - inside CompanyContext::acrossCompanies() → no scope applied (intentional bypass)
 *  - authenticated super-admin user            → no scope applied (vendor-side bypass)
 *  - no company set otherwise                  → throws CompanyContextMissingException (fail loud per §G)
 *  - company set                               → adds the WHERE clause, qualified by the model's table
 */
final class CompanyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(CompanyContext::class);

        if ($context->inAcrossCompaniesMode()) {
            return;
        }

        // Super-admin bypass — mirrors TenantScope. SA users have no
        // company_id by composite DB CHECK; their platform-side reads
        // legitimately cross tenants AND companies.
        $authUser = Auth::user();
        if ($authUser instanceof User && $authUser->isSuperAdmin()) {
            return;
        }

        if ($context->current() === null) {
            throw CompanyContextMissingException::forQuery($model::class);
        }

        $builder->where(
            $model->qualifyColumn('company_id'),
            $context->currentId()
        );
    }
}
