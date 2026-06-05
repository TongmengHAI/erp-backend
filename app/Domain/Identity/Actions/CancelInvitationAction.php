<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Exceptions\InvalidInvitationException;
use App\Domain\Identity\Models\Invitation;
use Illuminate\Support\Facades\DB;

/**
 * Cancel a pending invitation. Sets cancelled_at + cancelled_by_user_id
 * in one save() so the composite invitations_cancelled_consistency_check
 * CHECK is always satisfied.
 *
 * Refuses to cancel an already-accepted or already-cancelled row —
 * those are terminal states. Expired rows CAN be cancelled (the admin
 * may want to clean up the audit trail), but the cancellation has no
 * practical effect on the invitee — the row was already unusable.
 *
 * No self-action guard — cancelling an invitation can't target the
 * actor themselves (they're a registered user; they're not "the
 * invitee on this invitation").
 */
final class CancelInvitationAction
{
    public function execute(Invitation $invitation, int $actorId): Invitation
    {
        if ($invitation->accepted_at !== null) {
            throw InvalidInvitationException::accepted();
        }
        if ($invitation->cancelled_at !== null) {
            throw InvalidInvitationException::cancelled();
        }

        return DB::transaction(function () use ($invitation, $actorId): Invitation {
            $invitation->cancelled_at = now();
            $invitation->cancelled_by_user_id = $actorId;
            $invitation->save();

            return $invitation->fresh() ?? $invitation;
        });
    }
}
