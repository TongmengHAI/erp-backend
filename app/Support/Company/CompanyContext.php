<?php

declare(strict_types=1);

namespace App\Support\Company;

use App\Models\Company;
use App\Support\Company\Exceptions\CompanyContextMissingException;
use Closure;

/**
 * Request-scoped holder for the resolved company. Sits one scoping layer
 * below TenantContext.
 *
 * Bound as `scoped()` in AppServiceProvider — one instance per request,
 * fresh between requests, reset between queue jobs by the framework.
 *
 * Per CLAUDE.md §3 this MUST NOT be static and MUST NOT live in session.
 * The mirror of TenantContext's contract one layer down.
 */
final class CompanyContext
{
    private ?Company $company = null;

    /**
     * Depth counter so nested acrossCompanies() calls behave correctly.
     * Also used by CompanyScope to distinguish "company intentionally
     * cleared for cross-company work" from "company accidentally missing".
     */
    private int $acrossCompaniesDepth = 0;

    public function setCurrent(?Company $company): void
    {
        $this->company = $company;
    }

    public function current(): ?Company
    {
        return $this->company;
    }

    /**
     * @throws CompanyContextMissingException when no company is set
     */
    public function currentId(): int
    {
        if ($this->company === null) {
            throw new CompanyContextMissingException(
                $this->acrossCompaniesDepth > 0
                    ? 'currentId() called inside acrossCompanies() — pass company_id explicitly when writing cross-company records.'
                    : 'No company resolved on this request.'
            );
        }

        return $this->company->id;
    }

    public function inAcrossCompaniesMode(): bool
    {
        return $this->acrossCompaniesDepth > 0;
    }

    /**
     * Run a closure with the current company cleared. Used for consolidated
     * reporting within a tenant (group-level financials, cross-company
     * dashboards), and for seeders/console commands that touch multiple
     * companies. The previous company is restored on exit, even if the
     * closure throws.
     *
     * Tenant context is NOT cleared by this — only company. To clear both,
     * wrap acrossCompanies inside TenantContext::asSystem.
     *
     * @template T
     *
     * @param  Closure(): T  $fn
     * @return T
     */
    public function acrossCompanies(Closure $fn): mixed
    {
        $previousCompany = $this->company;
        $this->company = null;
        $this->acrossCompaniesDepth++;

        try {
            return $fn();
        } finally {
            $this->acrossCompaniesDepth--;
            $this->company = $previousCompany;
        }
    }
}
