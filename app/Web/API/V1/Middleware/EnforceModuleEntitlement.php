<?php

declare(strict_types=1);

namespace App\Web\API\V1\Middleware;

use App\Domain\Platform\Exceptions\ModuleNotEntitledException;
use App\Domain\Platform\Services\TenantEntitlementService;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-request guard that rejects access to a module-prefixed route group
 * when the current tenant does not have an Active entitlement.
 *
 * Applied as a route-group middleware with the module key as the
 * parameter:
 *
 *   Route::middleware('module:hrm')->prefix('hrm')->group(...)
 *   Route::middleware('module:hrm')->prefix('admin/hrm')->group(...)
 *
 * Behaviour:
 *   - super_admin user                    → bypass (mirrors TenantScope,
 *                                            CompanyScope, ResolveTenant,
 *                                            ResolveCompany bypasses)
 *   - tenant_user with status=Active       → 200 (continue to route)
 *   - tenant_user with status=Disabled OR
 *     no row OR soft-deleted row           → 403 ModuleNotEntitledException
 *                                            (error_code=module_not_entitled,
 *                                            module=<key>)
 *
 * Runs AFTER ResolveTenant — depends on TenantContext for the tenant
 * being checked. For routes that already include 'tenant' in their
 * middleware chain (which is every authenticated endpoint), ordering is
 * implicit.
 */
final class EnforceModuleEntitlement
{
    /**
     * App-layer allowlist of module keys (per Session 2's locked
     * decision: app-layer validation via LAUNCHER_APPS registry rather
     * than a DB enum). Adding a module ships an entry here AND in the
     * frontend's LAUNCHER_APPS registry.
     *
     * @var list<string>
     */
    private const KNOWN_MODULES = ['hrm'];

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly TenantEntitlementService $entitlementService,
    ) {}

    public function handle(Request $request, Closure $next, string $moduleKey): Response
    {
        if (! in_array($moduleKey, self::KNOWN_MODULES, true)) {
            // Routing config error — the route group named an unknown
            // module. Fail-loud at the framework layer rather than
            // silently allowing the request through.
            throw new InvalidArgumentException(
                sprintf('Unknown module key "%s" passed to EnforceModuleEntitlement.', $moduleKey),
            );
        }

        $user = $request->user();

        // Super-admin bypass. Same precedent as TenantScope, CompanyScope,
        // ResolveTenant, ResolveCompany — five places now key off
        // isSuperAdmin(). §10 candidate at slice-closer.
        if ($user instanceof User && $user->isSuperAdmin()) {
            return $next($request);
        }

        $tenant = $this->tenantContext->current();
        if ($tenant === null) {
            // Defensive: the 'tenant' middleware should have populated
            // context for any tenant_user. If we got here without it,
            // the route wiring is broken. Throw the same exception as
            // a missing entitlement — the user sees the same UX
            // regardless of which kind of break it is.
            throw new ModuleNotEntitledException($moduleKey);
        }

        if (! $this->entitlementService->isEntitled($tenant->id, $moduleKey)) {
            throw new ModuleNotEntitledException(
                $moduleKey,
                sprintf('The %s module is not entitled for this tenant.', strtoupper($moduleKey)),
            );
        }

        return $next($request);
    }
}
