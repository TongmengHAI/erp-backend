<?php

declare(strict_types=1);

namespace App\Support\Tenancy\Concerns;

use App\Models\Tenant;
use App\Support\Tenancy\Exceptions\TenantContextMissingException;
use App\Support\Tenancy\Scopes\TenantScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Applied to every Eloquent model whose table carries `tenant_id`.
 *
 * Responsibilities:
 *  1. Register the global TenantScope so reads are tenant-filtered.
 *  2. Auto-fill `tenant_id` on `creating` from TenantContext::currentId() —
 *     or throw if no tenant is resolvable and the model didn't set it explicitly.
 *  3. Expose `tenant()` relation, `forTenant(id)` and `acrossTenants()` query scopes.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model): void {
            if ($model->getAttribute('tenant_id') !== null) {
                return;
            }

            $context = app(TenantContext::class);

            if ($context->inSystemMode() || $context->current() === null) {
                throw new TenantContextMissingException(
                    sprintf(
                        'Cannot auto-fill tenant_id when creating %s — set tenant_id explicitly.',
                        static::class
                    )
                );
            }

            $model->setAttribute('tenant_id', $context->currentId());
        });
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Bypass the global tenant scope and target a specific tenant explicitly.
     * Use for cross-tenant maintenance or admin queries.
     */
    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->withoutGlobalScope(TenantScope::class)
            ->where($this->qualifyColumn('tenant_id'), $tenantId);
    }

    /**
     * Bypass the global tenant scope entirely. Use sparingly — anywhere this
     * appears, cross-tenant data may flow.
     */
    public function scopeAcrossTenants(Builder $query): Builder
    {
        return $query->withoutGlobalScope(TenantScope::class);
    }
}
