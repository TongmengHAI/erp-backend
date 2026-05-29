<?php

declare(strict_types=1);

namespace App\Support\Company\Middleware;

use App\Models\Company;
use App\Models\User;
use App\Support\Company\CompanyContext;
use App\Support\Company\Enums\CompanyStatus;
use App\Support\Company\Exceptions\CompanyAccessDeniedException;
use App\Support\Company\Exceptions\CompanyContextMissingException;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the active company for an authenticated user within their tenant
 * and pins it into the request-scoped CompanyContext.
 *
 * Runs AFTER ResolveTenant. Requires a resolved tenant (the user's home
 * tenant or current_tenant_id) — without one, the middleware returns
 * without setting company context. Unauthenticated requests pass through
 * untouched; the global CompanyScope rejects company-scoped queries by
 * design.
 *
 * Resolution chain (each step falls through to the next on miss):
 *
 *   Step 1 — Header override (X-Company-Id)
 *     Explicit user selection (frontend switcher). On success persist
 *     user.current_company_id. On invalid id: 403 CompanyAccessDeniedException.
 *
 *   Step 2 — user.current_company_id
 *     Last-used company; survives across sessions.
 *
 *   Step 3 — user.default_company_id
 *     User's preferred home. On success, also persist current_company_id.
 *
 *   Step 4 — Sole-company fallback
 *     If exactly one Active company exists in current tenant, pin it and
 *     backfill user.default + current. Single-company tenants work
 *     without any per-user config.
 *
 *   Step 5 — None matched
 *     Route can opt out via meta `companyOptional = true` — leaves context
 *     null, controller decides. Otherwise throws 401 with
 *     error_code='company_required' and an available_companies array.
 *
 * Verification at every step:
 *   (a) the target company belongs to the current tenant
 *   (b) status === Active
 * Cheap — single indexed SELECT against (tenant_id, id, status).
 *
 * User-company membership is IMPLICIT in H1a: every user in a tenant has
 * access to every active company in that tenant. H1c may introduce
 * explicit memberships (user_companies table) if needed; the resolution
 * chain's structure already accommodates that future addition.
 */
final class ResolveCompany
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly CompanyContext $companyContext,
    ) {}

    /**
     * Middleware parameter `optional` skips the throw on Step 5 — used by
     * routes like /auth/me where a no-company state is rendered as a picker
     * UI on the SPA. Applied via route declaration: `'company:optional'`.
     */
    public function handle(Request $request, Closure $next, ?string $option = null): Response
    {
        $user = $request->user();
        $tenant = $this->tenantContext->current();

        // Super-admin bypass. SA users have all four tenant/company FK
        // columns NULL by composite DB CHECK — walking the 5-branch
        // resolution chain would land at Step 5 and throw
        // company_required, blocking SA from ever reaching routes on the
        // `'company'` middleware group. The bypass leaves CompanyContext
        // unset; downstream code paths use CompanyScope's analogous SA
        // bypass or `acrossCompanies()` to operate without a pinned
        // company. Mirrors the ResolveTenant SA bypass and the
        // TenantScope SA early-out — three places, one rule.
        if ($user instanceof User && $user->isSuperAdmin()) {
            return $next($request);
        }

        // No authenticated user, or no resolved tenant — nothing to resolve
        // against. Company-scoped queries that follow will throw via the
        // global scope (fail-loud), as designed.
        if (! $user instanceof User || $tenant === null) {
            return $next($request);
        }

        $company = $this->resolveFor($request, $user, $tenant->id);

        if ($company === null) {
            // No company resolved. Routes that genuinely don't need company
            // context (e.g. /auth/me, which renders the picker UI) opt out
            // via the `company:optional` middleware parameter. Other routes
            // throw company_required.
            if ($option !== 'optional') {
                throw new CompanyContextMissingException(
                    'No company context resolved.',
                );
            }

            return $next($request);
        }

        $this->companyContext->setCurrent($company);

        return $next($request);
    }

    /**
     * Walk the 5-branch resolution chain. Returns the resolved Company, or
     * null if Step 5 is reached (caller decides whether that's an opt-out
     * route or a thrown error).
     *
     * Side effects: persists user.current_company_id (Step 1, Step 3, Step 4)
     * and user.default_company_id (Step 3, Step 4) when those branches fire.
     *
     * @throws CompanyAccessDeniedException when Step 1 is invoked with an
     *                                      invalid company (the user explicitly tried to switch and that
     *                                      choice can't be honored).
     */
    private function resolveFor(Request $request, User $user, int $tenantId): ?Company
    {
        // Step 1 — X-Company-Id header (explicit switch)
        $headerId = $request->header('X-Company-Id');
        if (is_string($headerId) && ctype_digit($headerId)) {
            $company = $this->fetchAccessibleCompany((int) $headerId, $tenantId);
            if ($company === null) {
                throw new CompanyAccessDeniedException(
                    sprintf('Company %s is not accessible in the current tenant.', $headerId),
                );
            }
            $this->persistChoice($user, $company, persistDefault: false);

            return $company;
        }

        // Step 2 — user.current_company_id (last-used)
        if ($user->current_company_id !== null) {
            $company = $this->fetchAccessibleCompany($user->current_company_id, $tenantId);
            if ($company !== null) {
                return $company;
            }
            // Stale — current company points at something inaccessible. Clear
            // it so we don't keep retrying the same bad id on every request.
            $user->forceFill(['current_company_id' => null])->save();
        }

        // Step 3 — user.default_company_id (preferred home)
        if ($user->default_company_id !== null) {
            $company = $this->fetchAccessibleCompany($user->default_company_id, $tenantId);
            if ($company !== null) {
                // Promote default → current so subsequent requests skip Step 3.
                $this->persistChoice($user, $company, persistDefault: false);

                return $company;
            }
            // Stale default. Don't auto-clear default (it's an admin-set
            // preference); just fall through and surface via the next steps.
        }

        // Step 4 — Sole-company fallback (single-company tenants)
        $accessibleCompanies = Company::query()
            ->where('tenant_id', $tenantId)
            ->where('status', CompanyStatus::Active->value)
            ->get();

        if ($accessibleCompanies->count() === 1) {
            /** @var Company $sole */
            $sole = $accessibleCompanies->first();
            $this->persistChoice($user, $sole, persistDefault: true);

            return $sole;
        }

        // Step 5 — Nothing matched. Caller handles (opt-out route OR throw).
        return null;
    }

    /**
     * Returns the company if it belongs to the given tenant AND is active,
     * else null. One indexed lookup; safe to call inside the resolution
     * chain on every request.
     */
    private function fetchAccessibleCompany(int $companyId, int $tenantId): ?Company
    {
        /** @var Company|null $company */
        $company = Company::query()
            ->where('id', $companyId)
            ->where('tenant_id', $tenantId)
            ->where('status', CompanyStatus::Active->value)
            ->first();

        return $company;
    }

    /**
     * Persist the user's company choice. Always updates current_company_id;
     * optionally also updates default_company_id (when promoting from
     * sole-fallback, or when default was never set).
     */
    private function persistChoice(User $user, Company $company, bool $persistDefault): void
    {
        $attrs = ['current_company_id' => $company->id];
        if ($persistDefault && $user->default_company_id === null) {
            $attrs['default_company_id'] = $company->id;
        }
        $user->forceFill($attrs)->save();
    }
}
