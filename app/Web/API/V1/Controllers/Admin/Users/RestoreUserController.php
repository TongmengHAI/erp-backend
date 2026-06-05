<?php

declare(strict_types=1);

namespace App\Web\API\V1\Controllers\Admin\Users;

use App\Domain\Identity\Actions\RestoreUserAction;
use App\Models\User;
use App\Web\API\V1\Controllers\Concerns\AuthorizesUserManagement;
use App\Web\API\V1\Controllers\Controller;
use App\Web\API\V1\Resources\Admin\AdminUserResource;
use Illuminate\Http\Request;

/**
 * POST /api/v1/admin/users/{userId}/restore.
 *
 * Transition: soft-deleted → restored (deleted_at = null). Inverse of
 * /deactivate. No self-action guard — a deactivated user can't reach
 * this endpoint.
 *
 * users.deactivate is the gating permission (same authority kind —
 * decisions about hard-removal lifecycle).
 */
final class RestoreUserController extends Controller
{
    use AuthorizesUserManagement;

    public function __invoke(Request $request, int $userId, RestoreUserAction $action): AdminUserResource
    {
        $this->authorizeUsersAccess($request);
        $this->authorizeUsersAction($request, 'users.deactivate');

        $target = User::withTrashed()->findOrFail($userId);
        $this->authorizeUserTargetIsInCurrentTenant($request, $target);

        $restored = $action->execute($target);
        $restored->load('roles');

        return new AdminUserResource($restored);
    }
}
