<?php

declare(strict_types=1);

namespace App\Web\API\V1\Controllers\Admin\Users;

use App\Domain\Identity\Actions\ResendInvitationAction;
use App\Domain\Identity\Models\Invitation;
use App\Web\API\V1\Controllers\Concerns\AuthorizesUserManagement;
use App\Web\API\V1\Controllers\Controller;
use App\Web\API\V1\Resources\Admin\AdminInvitationResource;
use Illuminate\Http\Request;

/**
 * POST /api/v1/admin/users/invitations/{invitationId}/resend.
 *
 * Soft-deletes the existing invitation and creates a fresh one with
 * a new token + new expires_at. Old token URL becomes structurally
 * invalid; admin's "re-send" button on a stale row Just Works.
 *
 * Per §10.14 — the new raw token is never returned to the admin;
 * it's shipped via UserInvited to the queued email listener.
 */
final class ResendInvitationController extends Controller
{
    use AuthorizesUserManagement;

    public function __invoke(
        Request $request,
        int $invitationId,
        ResendInvitationAction $action,
    ): AdminInvitationResource {
        $this->authorizeUsersAccess($request);
        $this->authorizeUsersAction($request, 'users.invite');

        // authorizeUsersAccess above guarantees $actor is not null.
        $actor = $request->user();
        assert($actor !== null);

        $existing = Invitation::query()
            ->where('id', $invitationId)
            ->where('tenant_id', $actor->tenant_id)
            ->firstOrFail();

        $lifetimeDays = (int) config('identity.invitation_lifetime_days', 7);

        $result = $action->execute($existing, (int) $actor->id, $lifetimeDays);

        return new AdminInvitationResource($result->invitation);
    }
}
