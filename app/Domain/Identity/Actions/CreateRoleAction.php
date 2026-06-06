<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Models\Role;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Create a custom (is_system=false) role within a tenant's scope.
 *
 * Inputs are pre-validated by CreateRoleRequest (name uniqueness +
 * permission ID validity). This Action focuses on the persistence
 * concern: create the row with the correct team_id, sync permissions,
 * return the fresh model with permissions loaded.
 *
 * Audit row fires via the Role model's Auditable trait (action=
 * 'created') — no special handling.
 *
 * Spatie's PermissionRegistrar::setPermissionsTeamId() is called
 * before Role::create() so the row gets team_id = $tenantId
 * automatically. The middleware-driven team_id is the canonical
 * mechanism; this Action makes it explicit so unit tests don't need
 * to pre-thread middleware.
 */
final class CreateRoleAction
{
    /**
     * @param  list<int>  $permissionIds
     */
    public function execute(
        int $tenantId,
        string $name,
        ?string $description,
        array $permissionIds,
    ): Role {
        return DB::transaction(function () use ($tenantId, $name, $description, $permissionIds): Role {
            app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);

            $role = Role::create([
                'name' => $name,
                'guard_name' => 'web',
                'team_id' => $tenantId,
                'is_system' => false,
                'description' => $description,
            ]);

            $role->syncPermissions($permissionIds);

            app(PermissionRegistrar::class)->forgetCachedPermissions();

            /** @var Role $fresh */
            $fresh = $role->fresh(['permissions']);

            return $fresh;
        });
    }
}
