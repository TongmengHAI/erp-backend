<?php

declare(strict_types=1);

namespace App\Web\API\V1\Controllers\Admin\Users;

use App\Domain\Identity\Actions\CancelInvitationAction;
use App\Domain\Identity\Models\Invitation;
use App\Web\API\V1\Controllers\Concerns\AuthorizesUserManagement;
use App\Web\API\V1\Controllers\Controller;
use App\Web\API\V1\Resources\Admin\AdminInvitationResource;
use Illuminate\Http\Request;

/**
 * POST /api/v1/admin/users/invitations/{invitationId}/cancel.
 *
 * State-machine transition: pending → cancelled. Throws
 * InvalidInvitationException for already-accepted / already-cancelled
 * rows (self-renders 422 with error_code).
 *
 * users.invite is the gating permission — same authority kind as
 * inviting (deciding who can join the tenant).
 */
final class CancelInvitationController extends Controller
{
    use AuthorizesUserManagement;

    public function __invoke(
        Request $request,
        int $invitationId,
        CancelInvitationAction $action,
    ): AdminInvitationResource {
        $this->authorizeUsersAccess($request);
        $this->authorizeUsersAction($request, 'users.invite');

        // authorizeUsersAccess above guarantees $actor is not null.
        $actor = $request->user();
        assert($actor !== null);

        $invitation = Invitation::query()
            ->where('id', $invitationId)
            ->where('tenant_id', $actor->tenant_id)
            ->firstOrFail();

        $cancelled = $action->execute($invitation, (int) $actor->id);

        return new AdminInvitationResource($cancelled);
    }
}
