<?php

declare(strict_types=1);

namespace App\Domain\Identity\Events;

use App\Domain\Identity\Models\Invitation;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired by InviteUserAction after the Invitation row is committed.
 *
 * Carries the raw token (NOT the hash) so the listener can compose
 * the acceptance URL. The token is short-lived in memory (one
 * queued-job lifetime) and never logged anywhere — Auditable's
 * filterAttributesForAudit + the explicit `$hidden = ['token_hash']`
 * on the model handle the storage side.
 *
 * Per CLAUDE.md §3 — domain events fire via DB::afterCommit() inside
 * the Action, so the listener sees a committed row.
 */
final class UserInvited
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Invitation $invitation,
        public string $rawToken,
    ) {}
}
