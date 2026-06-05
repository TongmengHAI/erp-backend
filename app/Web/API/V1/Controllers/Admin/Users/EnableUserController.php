<?php

declare(strict_types=1);

namespace App\Web\API\V1\Controllers\Admin\Users;

use App\Domain\Identity\Actions\EnableUserAction;
use App\Models\User;
use App\Web\API\V1\Controllers\Concerns\AuthorizesUserManagement;
use App\Web\API\V1\Controllers\Controller;
use App\Web\API\V1\Resources\Admin\AdminUserResource;
use Illuminate\Http\Request;

/**
 * POST /api/v1/admin/users/{userId}/enable.
 *
 * Transition: status='inactive' → 'active'. No self-action guard —
 * a disabled user can't reach this endpoint.
 */
final class EnableUserController extends Controller
{
    use AuthorizesUserManagement;

    public function __invoke(Request $request, int $userId, EnableUserAction $action): AdminUserResource
    {
        $this->authorizeUsersAccess($request);
        $this->authorizeUsersAction($request, 'users.update');

        $target = User::withTrashed()->findOrFail($userId);
        $this->authorizeUserTargetIsInCurrentTenant($request, $target);

        $enabled = $action->execute($target);
        $enabled->load('roles');

        return new AdminUserResource($enabled);
    }
}
