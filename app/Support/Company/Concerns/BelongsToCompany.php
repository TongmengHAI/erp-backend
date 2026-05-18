<?php

declare(strict_types=1);

namespace App\Support\Company\Concerns;

use App\Models\Company;
use App\Support\Company\CompanyContext;
use App\Support\Company\Exceptions\CompanyContextMissingException;
use App\Support\Company\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Applied to every Eloquent model whose table carries `company_id`. Mirrors
 * BelongsToTenant one scoping layer down.
 *
 * IMPORTANT: companies themselves do NOT use this trait. Company is an
 * identity-source model per CLAUDE.md §3 — it defines the company scope,
 * it doesn't live within it. Same pattern as User (identity source for
 * tenant, doesn't use BelongsToTenant after commit c519e10).
 *
 * Responsibilities:
 *  1. Register the global CompanyScope so reads are company-filtered.
 *  2. Auto-fill `company_id` on `creating` from CompanyContext::currentId() —
 *     or throw if no company is resolvable and the model didn't set it
 *     explicitly. Mirror of BelongsToTenant's auto-fill.
 *  3. Expose `company()` relation, `forCompany(id)` and `acrossCompanies()`
 *     query scopes.
 *
 * Models that use this trait typically also use BelongsToTenant. Both
 * global scopes apply; both filters are AND-joined. The auto-fill hooks
 * fire independently — passing an explicit `tenant_id` skips the tenant
 * auto-fill, passing an explicit `company_id` skips the company auto-fill.
 */
trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope(new CompanyScope);

        static::creating(function (Model $model): void {
            if ($model->getAttribute('company_id') !== null) {
                return;
            }

            $context = app(CompanyContext::class);

            if ($context->inAcrossCompaniesMode() || $context->current() === null) {
                throw new CompanyContextMissingException(
                    sprintf(
                        'Cannot auto-fill company_id when creating %s — set company_id explicitly.',
                        static::class
                    )
                );
            }

            $model->setAttribute('company_id', $context->currentId());
        });
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Bypass the global company scope and target a specific company explicitly.
     * Use for cross-company maintenance or admin queries WITHIN a tenant.
     * Cross-tenant access remains forbidden by TenantScope.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->withoutGlobalScope(CompanyScope::class)
            ->where($this->qualifyColumn('company_id'), $companyId);
    }

    /**
     * Bypass the global company scope entirely. Use for consolidated
     * reporting within a tenant (group financials, etc.) — cross-company
     * data may flow. Cross-tenant access still blocked by TenantScope.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeAcrossCompanies(Builder $query): Builder
    {
        return $query->withoutGlobalScope(CompanyScope::class);
    }
}
