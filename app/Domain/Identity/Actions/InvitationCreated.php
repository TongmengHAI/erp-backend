<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Models\Invitation;

/**
 * Result DTO for InviteUserAction and ResendInvitationAction.
 *
 * Carries the raw token alongside the persisted Invitation so the
 * caller (controller layer) can pass the raw token to the
 * UserInvited event for email rendering. The raw token is NEVER
 * stored — only the SHA-256 hash lands in token_hash.
 */
final readonly class InvitationCreated
{
    public function __construct(
        public Invitation $invitation,
        public string $rawToken,
    ) {}
}
