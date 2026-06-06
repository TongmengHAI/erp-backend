<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Exceptions\RoleImmutableException;
use App\Domain\Identity\Exceptions\RoleInUseException;
use App\Domain\Identity\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Soft-delete a custom role.
 *
 * Two precondition checks (both throw self-rendering exceptions):
 *
 *   1. System role guard — is_system=true → RoleImmutableException
 *      (403 with error_code='system_role_immutable').
 *
 *   2. In-use guard — at least one user currently has this role
 *      assigned → RoleInUseException (422 with error_code='role_in_use'
 *      AND users_count). Per Phase 2B Q6, no fallback / orphan logic
 *      — the admin must manually reassign the affected users before
 *      deleting the role.
 *
 * The two checks are SEPARATE Action-layer guards, not collapsed into
 * one "can this be deleted?" predicate, because each surfaces a
 * different error_code + frontend UX path. Per CLAUDE.md §C the
 * "BlockRole..." concept is a precondition CHECK on this Action, not
 * its own Action.
 *
 * Soft-delete preserves model_has_roles join rows so a future restore
 * (Session 4 RoleListPage's Restore button, or admin tooling) brings
 * the role + all assignments back as a unit. The default scope on
 * Role excludes soft-deleted rows from role-options dropdowns;
 * assigned users effectively lose those role-derived permissions
 * until restoration.
 *
 * Audit row fires via the Role model's Auditable trait
 * (action='soft_deleted' per §10.24 — pinned by RoleAuditableTest
 * in Session 1).
 */
final class DeleteRoleAction
{
    public function execute(Role $role): void
    {
        if ($role->is_system) {
            throw new RoleImmutableException(actionName: 'delete');
        }

        $assignedCount = (int) DB::table('model_has_roles')
            ->where('role_id', $role->id)
            ->where('model_type', User::class)
            ->count();

        if ($assignedCount > 0) {
            throw new RoleInUseException(usersCount: $assignedCount);
        }

        DB::transaction(static function () use ($role): void {
            $role->delete();
        });
    }
}
