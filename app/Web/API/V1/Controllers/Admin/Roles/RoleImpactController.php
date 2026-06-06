<?php

declare(strict_types=1);

namespace App\Web\API\V1\Controllers\Admin\Roles;

use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Services\RoleImpactService;
use App\Web\API\V1\Controllers\Concerns\AuthorizesRoleManagement;
use App\Web\API\V1\Controllers\Controller;
use App\Web\API\V1\Requests\Admin\Roles\RoleImpactRequest;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/admin/roles/{role}/impact?removed_permissions[]=...
 *
 * Returns the user-impact preview the SPA's RoleUpdateWarning dialog
 * shows before saving a custom-role permission removal. Read-side
 * only — no writes; safe to call repeatedly while the admin edits the
 * permission picker.
 *
 * ╔══════════════════════════════════════════════════════════════════╗
 * ║  OVER-WARN SEMANTIC (locked decision, plan Q5).                  ║
 * ║                                                                  ║
 * ║  affected_users_count = number of users currently assigned this  ║
 * ║  role. NOT "users who would lose effective coverage of the       ║
 * ║  removed permissions after the save."                            ║
 * ║                                                                  ║
 * ║  When a user has the same permission via multiple roles, this    ║
 * ║  count OVER-REPORTS — the user wouldn't actually lose the        ║
 * ║  permission. That is INTENTIONAL.                                ║
 * ║                                                                  ║
 * ║  Rationale: over-warning is safer than under-warning. The admin  ║
 * ║  makes the right decision either way (cancel + investigate vs.   ║
 * ║  proceed). Under-warning would suggest a permission removal is   ║
 * ║  harmless when it actually impacted a user.                      ║
 * ║                                                                  ║
 * ║  DO NOT "FIX" THIS by adding the cross-role coverage check.      ║
 * ║  Future Claude Code session reading this comment: the simpler    ║
 * ║  query is the locked decision, not an oversight. If the over-    ║
 * ║  warning becomes a UX problem in practice, the right move is a   ║
 * ║  separate "advanced impact" endpoint (different URL + response   ║
 * ║  shape), not retrofitting this one.                              ║
 * ╚══════════════════════════════════════════════════════════════════╝
 *
 * Auth gates:
 *   - roles.view at access level (404 if missing — same as the rest
 *     of /admin/roles/*).
 *   - roles.update at action level (403 if missing — the impact
 *     preview is a precondition for saving an update, so it gates on
 *     the same permission as the save).
 *   - System roles return 403 with error_code='system_role_immutable'
 *     for symmetry with the PATCH endpoint (the SPA shouldn't be
 *     asking for impact on a role it can't edit; if it does, fail
 *     loud with the same error_code so the UI can branch).
 *
 * Response shape (200):
 *   {
 *     "data": {
 *       "affected_users_count": 3,
 *       "affected_users_preview": [
 *         {"id": 1, "name": "Alice"},
 *         {"id": 2, "name": "Bob"},
 *         {"id": 3, "name": "Carol"}
 *       ]
 *     }
 *   }
 */
final class RoleImpactController extends Controller
{
    use AuthorizesRoleManagement;

    public function __invoke(
        RoleImpactRequest $request,
        int $roleId,
        RoleImpactService $service,
    ): JsonResponse {
        $this->authorizeRolesAccess($request);
        $this->authorizeRolesAction($request, 'roles.update');

        $role = Role::findOrFail($roleId);
        $this->authorizeRoleTargetIsInCurrentTenant($request, $role);

        if ($role->is_system) {
            return new JsonResponse([
                'message' => 'System roles cannot be modified.',
                'error_code' => 'system_role_immutable',
                'action' => 'update',
            ], 403);
        }

        /** @var array{removed_permissions: list<string>} $data */
        $data = $request->validated();

        $impact = $service->compute($role, $data['removed_permissions']);

        return new JsonResponse(['data' => $impact]);
    }
}
