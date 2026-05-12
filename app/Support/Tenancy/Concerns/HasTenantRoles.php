<?php

declare(strict_types=1);

namespace App\Support\Tenancy\Concerns;

use App\Models\Tenant;
use Closure;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Spatie\Permission\Contracts\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Tenant-aware wrapper for Spatie's HasRoles trait. Applied alongside HasRoles
 * (NOT instead of it) on models that participate in per-tenant RBAC.
 *
 * Spatie's HasRoles already does most of the heavy lifting once the
 * PermissionRegistrar's team_id is set — this trait adds the small set of
 * methods that need cross-tenant context or transactional safety.
 *
 * ResolveTenant middleware sets the registrar's team_id at the request
 * boundary. AppServiceProvider resets it before each queue job. The trait
 * methods below temporarily flip it for cross-tenant administrative work
 * (e.g. seeders, "invite this user to that tenant" flows), restoring the
 * previous value on exit — including on exception.
 */
trait HasTenantRoles
{
    /**
     * Assign a Spatie role to this user within a specific tenant. Wrapped in a
     * transaction so the role assignment + any side effects commit atomically.
     *
     * The registrar's team_id is set for the closure's duration and restored
     * afterwards, even if the closure throws. This keeps the per-request
     * tenant context intact when admin code temporarily acts on a different tenant.
     */
    public function assignTenantRole(Tenant $tenant, string|Role $role): void
    {
        $this->withTenantTeam($tenant, function () use ($role): void {
            DB::transaction(function () use ($role): void {
                /** @phpstan-ignore method.notFound */
                $this->assignRole($role);
            });
        });
    }

    public function revokeTenantRole(Tenant $tenant, string|Role $role): void
    {
        $this->withTenantTeam($tenant, function () use ($role): void {
            DB::transaction(function () use ($role): void {
                /** @phpstan-ignore method.notFound */
                $this->removeRole($role);
            });
        });
    }

    /**
     * True when this user has ANY Spatie role row scoped to the given tenant.
     * Doesn't care which role(s) — just answers "is there a membership at all?"
     *
     * Bypasses Spatie's `roles()` relation deliberately: that relation respects
     * the registrar's *current* team_id, which is wrong for this membership
     * check — we want to know if rows exist for the *given* tenant regardless
     * of which tenant is currently "active" in the request.
     */
    public function belongsToTenant(Tenant $tenant): bool
    {
        $table = config('permission.table_names.model_has_roles');
        $teamColumn = config('permission.column_names.team_foreign_key', 'team_id');

        if (! is_string($table)) {
            throw new InvalidArgumentException('permission.table_names.model_has_roles must be a string.');
        }

        return DB::table($table)
            ->where('model_type', $this->getMorphClass())
            ->where('model_id', $this->getKey())
            ->where($teamColumn, $tenant->id)
            ->exists();
    }

    /**
     * Home tenant (tenant_id), distinct from the currently-active tenant.
     * Returns null only for super-admin/system users who don't belong to a tenant.
     */
    public function defaultTenant(): ?Tenant
    {
        /** @var int|null $tenantId */
        $tenantId = $this->getAttribute('tenant_id');

        return $tenantId === null ? null : Tenant::query()->find($tenantId);
    }

    /**
     * Run a closure with the Spatie registrar's team_id temporarily set to
     * the given tenant. Previous team_id is restored on exit (even on exception).
     *
     * @template T
     *
     * @param  Closure(): T  $fn
     * @return T
     */
    private function withTenantTeam(Tenant $tenant, Closure $fn): mixed
    {
        $registrar = app(PermissionRegistrar::class);
        $previous = $registrar->getPermissionsTeamId();

        $registrar->setPermissionsTeamId($tenant->id);

        try {
            return $fn();
        } finally {
            $registrar->setPermissionsTeamId($previous);
        }
    }
}
