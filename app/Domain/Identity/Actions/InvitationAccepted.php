<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Models\Invitation;
use App\Models\User;

/**
 * Result DTO for AcceptInvitationAction. Carries both the accepted
 * invitation row (with accepted_at + accepted_user_id populated)
 * and the newly created User so the caller can auto-login and
 * surface tenant context per Q4 (auto-login after signup).
 */
final readonly class InvitationAccepted
{
    public function __construct(
        public Invitation $invitation,
        public User $user,
    ) {}
}
