<?php

declare(strict_types=1);

namespace App\Web\API\V1\Controllers\Admin\Users;

use App\Domain\Identity\Actions\DisableUserAction;
use App\Models\User;
use App\Web\API\V1\Controllers\Concerns\AuthorizesUserManagement;
use App\Web\API\V1\Controllers\Controller;
use App\Web\API\V1\Resources\Admin\AdminUserResource;
use Illuminate\Http\Request;

/**
 * POST /api/v1/admin/users/{userId}/disable.
 *
 * Transition: status='active' → 'inactive'. Reversible via /enable.
 *
 * Self-disable blocked by the Action (SelfActionForbiddenException
 * → 403 error_code='self_action_forbidden'). Per Phase 2A locked
 * decision: defense-in-depth alongside the UI hiding the button on
 * the current user's own row.
 */
final class DisableUserController extends Controller
{
    use AuthorizesUserManagement;

    public function __invoke(Request $request, int $userId, DisableUserAction $action): AdminUserResource
    {
        $this->authorizeUsersAccess($request);
        $this->authorizeUsersAction($request, 'users.disable');

        $target = User::withTrashed()->findOrFail($userId);
        $this->authorizeUserTargetIsInCurrentTenant($request, $target);

        $actor = $request->user();
        $actorId = $actor !== null ? (int) $actor->id : 0;

        $disabled = $action->execute($target, $actorId);
        $disabled->load('roles');

        return new AdminUserResource($disabled);
    }
}
