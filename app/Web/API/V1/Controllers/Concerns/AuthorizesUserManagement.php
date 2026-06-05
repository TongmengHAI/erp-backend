<?php

declare(strict_types=1);

namespace App\Web\API\V1\Controllers\Concerns;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * Authorization chokepoint for /admin/users/* controllers — mirrors the
 * AuthorizesHrmAccess trait's shape and intent for the users.* domain.
 *
 * Two distinct gates:
 *
 *   authorizeUsersAccess() — 404 if the user lacks users.view. This is
 *     the "feature is invisible to you" gate. Non-admins (every role
 *     other than tenant_admin in Phase 2A) lack users.view → the
 *     /admin/users/* surface effectively doesn't exist for them.
 *
 *   authorizeUsersAction($perm) — 403 if the user lacks the specific
 *     action permission (users.update / users.disable / users.deactivate).
 *     They could see the surface (they have users.view) but not perform
 *     this specific action. This branch is unreachable in Phase 2A
 *     because all five users.* permissions are granted together to
 *     tenant_admin — but the structural split lands now so the Phase 2B
 *     subset-by-role decision doesn't require a re-architecture.
 *
 *   authorizeUserTargetIsInCurrentTenant($target) — 404 if the target
 *     User belongs to a different tenant (or no tenant — Super Admin).
 *     User is NOT tenant-scoped via global scope (per the User model's
 *     identity-source docblock), so the cross-tenant check happens here
 *     in the controller layer.
 */
trait AuthorizesUserManagement
{
    protected function authorizeUsersAccess(Request $request): void
    {
        $user = $request->user();
        if ($user === null || ! $user->can('users.view')) {
            abort(404);
        }
    }

    protected function authorizeUsersAction(Request $request, string $permission): void
    {
        $user = $request->user();
        if ($user === null || ! $user->can($permission)) {
            abort(403);
        }
    }

    protected function authorizeUserTargetIsInCurrentTenant(Request $request, User $target): void
    {
        $actor = $request->user();
        // Cross-tenant lookup OR super-admin target → 404 (the row
        // effectively doesn't exist within the actor's scope).
        if ($actor === null || $target->tenant_id !== $actor->tenant_id) {
            abort(404);
        }
    }
}
