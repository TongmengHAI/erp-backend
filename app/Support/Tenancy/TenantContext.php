<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Models\Tenant;
use App\Support\Tenancy\Exceptions\TenantContextMissingException;
use Closure;

/**
 * Request-scoped holder for the resolved tenant.
 *
 * Bound as `scoped()` in AppServiceProvider — one instance per request,
 * fresh between requests (and reset between queue jobs by the framework).
 *
 * Per §3 this MUST NOT be static and MUST NOT live in session.
 */
final class TenantContext
{
    private ?Tenant $tenant = null;

    /**
     * Depth counter so nested asSystem() calls behave correctly.
     * Also used by TenantScope to distinguish "tenant intentionally cleared"
     * from "tenant accidentally missing".
     */
    private int $systemDepth = 0;

    public function setCurrent(?Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function current(): ?Tenant
    {
        return $this->tenant;
    }

    /**
     * @throws TenantContextMissingException when no tenant is set
     */
    public function currentId(): int
    {
        if ($this->tenant === null) {
            throw new TenantContextMissingException(
                $this->systemDepth > 0
                    ? 'currentId() called inside asSystem() — pass tenant_id explicitly when writing system records.'
                    : 'No tenant resolved on this request.'
            );
        }

        return $this->tenant->id;
    }

    public function inSystemMode(): bool
    {
        return $this->systemDepth > 0;
    }

    /**
     * Run a closure with the current tenant cleared. Used by seeders, console
     * commands, and any cross-tenant maintenance code. The previous tenant is
     * restored on exit, even if the closure throws.
     *
     * @template T
     *
     * @param  Closure(): T  $fn
     * @return T
     */
    public function asSystem(Closure $fn): mixed
    {
        $previousTenant = $this->tenant;
        $this->tenant = null;
        $this->systemDepth++;

        try {
            return $fn();
        } finally {
            $this->systemDepth--;
            $this->tenant = $previousTenant;
        }
    }
}
