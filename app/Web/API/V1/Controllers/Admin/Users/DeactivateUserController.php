<?php

declare(strict_types=1);

namespace App\Web\API\V1\Controllers\Admin\Users;

use App\Domain\Identity\Actions\DeactivateUserAction;
use App\Models\User;
use App\Web\API\V1\Controllers\Concerns\AuthorizesUserManagement;
use App\Web\API\V1\Controllers\Controller;
use App\Web\API\V1\Resources\Admin\AdminUserResource;
use Illuminate\Http\Request;

/**
 * POST /api/v1/admin/users/{userId}/deactivate.
 *
 * Transition: soft-delete (deleted_at set). Recoverable via /restore.
 *
 * Self-deactivate blocked by the Action (SelfActionForbiddenException
 * → 403). Defense-in-depth alongside UI per Phase 2A locked decision.
 */
final class DeactivateUserController extends Controller
{
    use AuthorizesUserManagement;

    public function __invoke(Request $request, int $userId, DeactivateUserAction $action): AdminUserResource
    {
        $this->authorizeUsersAccess($request);
        $this->authorizeUsersAction($request, 'users.deactivate');

        $target = User::withTrashed()->findOrFail($userId);
        $this->authorizeUserTargetIsInCurrentTenant($request, $target);

        $actor = $request->user();
        $actorId = $actor !== null ? (int) $actor->id : 0;

        $deactivated = $action->execute($target, $actorId);
        $deactivated->load('roles');

        return new AdminUserResource($deactivated);
    }
}
