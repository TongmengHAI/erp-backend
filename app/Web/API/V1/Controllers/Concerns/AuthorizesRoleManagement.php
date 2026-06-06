<?php

declare(strict_types=1);

namespace App\Web\API\V1\Controllers\Concerns;

use App\Domain\Identity\Models\Role;
use Illuminate\Http\Request;

/**
 * Authorization chokepoint for /admin/roles/* controllers — mirrors the
 * AuthorizesUserManagement trait's shape and intent for the roles.* domain.
 *
 * Three gates, same pattern as users:
 *
 *   authorizeRolesAccess() — 404 if the user lacks roles.view. The
 *     "feature is invisible to you" gate; consistent with the §10.6
 *     feature-hide convention.
 *
 *   authorizeRolesAction($perm) — 403 if the user lacks the specific
 *     action permission (roles.create / roles.update / roles.delete).
 *     Caller has the surface (roles.view), but not authority for this
 *     specific action.
 *
 *   authorizeRoleTargetIsInCurrentTenant($role) — 404 if the role
 *     belongs to a different tenant's custom-role scope. System roles
 *     (team_id=NULL) are visible to every authenticated tenant_admin
 *     across tenants, so they pass this check; custom roles must have
 *     team_id matching the actor's tenant_id. Mirrors the
 *     authorizeUserTargetIsInCurrentTenant shape for symmetry.
 */
trait AuthorizesRoleManagement
{
    protected function authorizeRolesAccess(Request $request): void
    {
        $user = $request->user();
        if ($user === null || ! $user->can('roles.view')) {
            abort(404);
        }
    }

    protected function authorizeRolesAction(Request $request, string $permission): void
    {
        $user = $request->user();
        if ($user === null || ! $user->can($permission)) {
            abort(403);
        }
    }

    protected function authorizeRoleTargetIsInCurrentTenant(Request $request, Role $role): void
    {
        $actor = $request->user();
        if ($actor === null) {
            abort(404);
        }

        // System roles are global; the actor's tenant context doesn't
        // restrict visibility. Custom roles must belong to this tenant.
        if ($role->is_system) {
            return;
        }

        if ($role->team_id !== $actor->tenant_id) {
            abort(404);
        }
    }
}
