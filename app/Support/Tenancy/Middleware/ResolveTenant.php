<?php

declare(strict_types=1);

namespace App\Support\Tenancy\Middleware;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\Enums\TenantStatus;
use App\Support\Tenancy\Exceptions\TenantAccessDeniedException;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the active tenant for an authenticated user and pins it into the
 * request-scoped TenantContext.
 *
 * Resolution rule (slice 2 — pre-multi-tenant-membership):
 *   target = $user->current_tenant_id ?? $user->tenant_id
 *   then validate the target exists and has status=active.
 *
 * Unauthenticated requests pass through with no context set — the global
 * TenantScope will reject any tenant-scoped query they attempt, by design.
 *
 * Header-based tenant override (X-Tenant-Id) lands in slice 6 once
 * user_tenant_roles makes "user belongs to multiple tenants" a real state.
 *
 * Not registered in any route group in this slice — slice 3 wires it in.
 */
final class ResolveTenant
{
    public function __construct(
        private readonly TenantContext $context,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User) {
            $this->context->setCurrent($this->resolveFor($user));
        }

        return $next($request);
    }

    /**
     * @throws TenantAccessDeniedException
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
            throw new TenantAccessDeniedException(
                sprintf('Tenant %s is %s.', $tenant->slug, $tenant->status->value)
            );
        }

        return $tenant;
    }
}
