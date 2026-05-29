<?php

declare(strict_types=1);

namespace App\Support\Tenancy\Middleware;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\Enums\TenantStatus;
use App\Support\Tenancy\Exceptions\TenantAccessDeniedException;
use App\Support\Tenancy\Exceptions\TenantInactiveException;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the active tenant for an authenticated user and pins it into the
 * request-scoped TenantContext + Spatie PermissionRegistrar.
 *
 * Resolution rule (slice 2 — pre-multi-tenant-membership):
 *   target = $user->current_tenant_id ?? $user->tenant_id
 *   then validate the target exists and has status=active.
 *
 * Failure modes:
 *   - User has no tenant ids set         → 403 TenantAccessDeniedException
 *   - Tenant id points at nothing        → 403 TenantAccessDeniedException
 *   - Tenant exists but is not active    → 401 TenantInactiveException
 *                                          (error_code=tenant_inactive)
 *
 * Unauthenticated requests pass through with no context — the global
 * TenantScope will reject any tenant-scoped query they attempt, by design.
 *
 * Header-based tenant override (X-Tenant-Id) lands in a later slice once
 * user_tenant_roles makes "user belongs to multiple tenants" a real state.
 */
final class ResolveTenant
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly PermissionRegistrar $registrar,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Super-admin bypass. SA users carry no tenant_id by composite DB
        // CHECK — calling resolveFor() would throw TenantAccessDeniedException
        // (both tenant_id and current_tenant_id null → "no resolvable tenant").
        // The bypass leaves TenantContext unset; downstream code paths use
        // TenantScope's SA bypass or `asSystem()` to operate without a pinned
        // tenant. Spatie's PermissionRegistrar is also intentionally left
        // unset — SA gating is via the user-type flag (SuperAdminGuard
        // middleware in Session 2), not via Spatie permissions.
        if ($user instanceof User && ! $user->isSuperAdmin()) {
            $tenant = $this->resolveFor($user);
            $this->context->setCurrent($tenant);
            $this->registrar->setPermissionsTeamId($tenant->id);
        }

        return $next($request);
    }

    /**
     * @throws TenantAccessDeniedException
     * @throws TenantInactiveException
     */
    private function resolveFor(User $user): Tenant
    {
        $tenantId = $user->current_tenant_id ?? $user->tenant_id;

        if ($tenantId === null) {
            throw new TenantAccessDeniedException(
                sprintf('User %d has no resolvable tenant (tenant_id and current_tenant_id are both null).', $user->id)
            );
        }

        /** @var Tenant|null $tenant */
        $tenant = Tenant::query()->find($tenantId);

        if ($tenant === null) {
            throw new TenantAccessDeniedException(
                sprintf('Tenant %d does not exist or has been deleted.', $tenantId)
            );
        }

        if ($tenant->status !== TenantStatus::Active) {
            throw new TenantInactiveException(
                sprintf('Tenant %s is %s.', $tenant->slug, $tenant->status->value)
            );
        }

        return $tenant;
    }
}
