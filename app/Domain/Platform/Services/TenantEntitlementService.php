<?php

declare(strict_types=1);

namespace App\Domain\Platform\Services;

use App\Domain\Platform\Enums\ModuleStatus;
use App\Domain\Platform\Models\TenantModule;
use App\Support\Tenancy\TenantContext;

/**
 * Read-side service for tenant module entitlement (per §10.3 — single
 * source of read-time truth for the entitlement state across three
 * consumers):
 *
 *   1. EnforceModuleEntitlement middleware (per-request 403 check)
 *   2. /auth/me response (the entitled_modules array the SPA filters
 *      LAUNCHER_APPS against)
 *   3. Future TenantModuleController::index (Session 3 SA endpoint)
 *
 * Each consumer reads through this service. Swap-to-cache later (e.g.
 * Redis-backed; entitlement state changes are infrequent) becomes a
 * single-file change; consumers remain unchanged.
 *
 * Reads always go through TenantContext (or the optional explicit
 * tenant_id arg for SA-side reads where there's no pinned tenant).
 * TenantModule uses BelongsToTenant — the SA bypass on TenantScope
 * means SA reads work without setting context.
 */
final class TenantEntitlementService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * Module keys this tenant currently has entitled — status = Active
     * AND deleted_at IS NULL. Returns an empty array if no tenant is
     * resolvable (e.g. an SA's /auth/me).
     *
     * @return list<string>
     */
    public function entitledModuleKeysFor(?int $tenantId = null): array
    {
        $resolvedTenantId = $tenantId ?? $this->tenantContext->current()?->id;
        if ($resolvedTenantId === null) {
            return [];
        }

        /** @var list<string> $keys */
        $keys = TenantModule::query()
            ->acrossTenants() // explicit tenant_id filter below; bypass TenantScope
            ->where('tenant_id', $resolvedTenantId)
            ->where('status', ModuleStatus::Active->value)
            ->pluck('module_key')
            ->values()
            ->all();

        return $keys;
    }

    /**
     * Whether the given tenant has an Active entitlement to the given
     * module key. Used by EnforceModuleEntitlement on every request to
     * a module-prefixed route group.
     *
     * Uses acrossTenants() to bypass TenantScope — the explicit tenant_id
     * arg is the load-bearing filter. The middleware passes the tenant
     * already resolved by ResolveTenant; we want to read THAT tenant's
     * row regardless of whether TenantContext happens to be set (e.g.
     * EnforceModuleEntitlement runs after ResolveTenant, but is also
     * exercised by tests that haven't set context).
     */
    public function isEntitled(int $tenantId, string $moduleKey): bool
    {
        return TenantModule::query()
            ->acrossTenants()
            ->where('tenant_id', $tenantId)
            ->where('module_key', $moduleKey)
            ->where('status', ModuleStatus::Active->value)
            ->exists();
    }
}
