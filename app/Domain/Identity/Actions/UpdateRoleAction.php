<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Exceptions\RoleImmutableException;
use App\Domain\Identity\Models\Role;
use App\Models\User;
use App\Support\Audit\Services\AuditWriter;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Update a custom role's name / description / permissions.
 *
 * Rejects mutation on system roles (is_system=true) by throwing
 * RoleImmutableException (self-renders 403). Defense-in-depth — the
 * FormRequest also validates this, but the Action enforces it
 * independently so a future direct caller can't bypass the rule.
 *
 * Per-affected-user audit rows (Phase 2B locked decision Q5): when a
 * permission is REMOVED from the role, write one audit_logs row per
 * user currently assigned this role, recording which permissions they
 * lost. Two query shapes against audit_logs flow from this:
 *
 *   1. "what happened to this role?" — auditable_type=Role,
 *      auditable_id=$role->id (the role's own Auditable-trait row).
 *   2. "what happened to this user's effective permissions?" —
 *      auditable_type=User, auditable_id=$user->id,
 *      action='permissions_revoked_via_role'.
 *
 * Neither query needs to join through role-version history — both are
 * direct equality lookups on (auditable_type, auditable_id).
 *
 * The role's own audit row fires via the Role model's Auditable trait
 * (action='updated' with a before/after diff on name + description).
 * The per-affected-user rows are written via the AuditWriter::record
 * static helper — there is no Eloquent event on User to hook for
 * "permissions changed via a role." Surgical, explicit, easy to grep.
 *
 * The role_id + role_name are embedded in the audit row's `before`
 * JSON so a future audit query can identify WHICH role triggered the
 * revocation (audit_logs has no `metadata` column today).
 */
final class UpdateRoleAction
{
    /**
     * @param  list<int>|null  $permissionIds  null = don't touch permissions
     */
    public function execute(
        Role $role,
        ?string $name,
        ?string $description,
        ?array $permissionIds,
    ): Role {
        if ($role->is_system) {
            throw new RoleImmutableException(actionName: 'update');
        }

        return DB::transaction(function () use ($role, $name, $description, $permissionIds): Role {
            $tenantId = $role->team_id;
            if ($tenantId !== null) {
                app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);
            }

            if ($name !== null) {
                $role->name = $name;
            }
            if ($description !== null) {
                $role->description = $description;
            }
            if ($role->isDirty()) {
                $role->save();
            }

            if ($permissionIds !== null) {
                $before = $role->permissions->pluck('name')->all();
                $role->syncPermissions($permissionIds);
                $role->load('permissions');
                $after = $role->permissions->pluck('name')->all();
                $removed = array_values(array_diff($before, $after));

                if ($removed !== []) {
                    $this->writePerUserPermissionRevocationAudits($role, $removed);
                }
            }

            app(PermissionRegistrar::class)->forgetCachedPermissions();

            /** @var Role $fresh */
            $fresh = $role->fresh(['permissions']);

            return $fresh;
        });
    }

    /**
     * Write one audit_logs row per user currently assigned $role,
     * documenting which permissions they lost via this role change.
     * Per Phase 2B locked decision Q5 + CLAUDE.md §10.20 (defense-
     * in-depth via per-layer audit signal).
     *
     * @param  list<string>  $removedPermissions
     */
    private function writePerUserPermissionRevocationAudits(Role $role, array $removedPermissions): void
    {
        $userIds = DB::table('model_has_roles')
            ->where('role_id', $role->id)
            ->where('model_type', User::class)
            ->pluck('model_id')
            ->all();

        if ($userIds === []) {
            return;
        }

        foreach ($userIds as $userId) {
            /** @var User|null $user */
            $user = User::find($userId);
            if ($user === null) {
                continue;
            }

            AuditWriter::record(
                model: $user,
                action: 'permissions_revoked_via_role',
                before: [
                    'permissions' => $removedPermissions,
                    'role_id' => $role->id,
                    'role_name' => $role->name,
                ],
                after: ['permissions' => []],
            );
        }
    }
}
