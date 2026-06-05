<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Events\UserInvited;
use App\Domain\Identity\Models\Invitation;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Create a pending invitation row and dispatch UserInvited.
 *
 * Pre-validation invariants (enforced by InviteUserRequest's
 * withValidator — Action assumes them):
 *
 *   • email is not a registered user in ANY tenant
 *     (error_code=email_globally_registered) — per the Phase 2A
 *     Q10 Option A resolution. users.email is GLOBALLY unique.
 *   • no active invitation exists for (tenant_id, email)
 *     (error_code=active_invitation_exists) — Q11.
 *   • role_id resolves to a real role.
 *
 * The Action itself focuses on the write: insert the row inside a
 * transaction, then dispatch UserInvited via DB::afterCommit so the
 * queued listener never sees an uncommitted row.
 *
 * Token discipline: raw token is generated here, hashed into the row,
 * and returned alongside the persisted Invitation via InvitationCreated.
 * The controller passes raw token to the event; the event ships it to
 * the queued listener which composes the URL. After this method
 * returns, the controller MUST NOT log or persist $rawToken anywhere.
 * Per CLAUDE.md §10.14 — one-time-secret lifecycle.
 */
final class InviteUserAction
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    public function execute(
        Tenant $tenant,
        string $email,
        ?string $name,
        int $roleId,
        int $invitedByUserId,
        int $lifetimeDays,
    ): InvitationCreated {
        $rawToken = Invitation::generateRawToken();

        $invitation = DB::transaction(function () use ($tenant, $email, $name, $roleId, $invitedByUserId, $rawToken, $lifetimeDays): Invitation {
            return $this->tenantContext->asSystem(static fn (): Invitation => Invitation::query()->create([
                'tenant_id' => $tenant->id,
                'email' => $email,
                'name' => $name,
                'role_id' => $roleId,
                'token_hash' => Invitation::hashToken($rawToken),
                'invited_by_user_id' => $invitedByUserId,
                'expires_at' => now()->addDays($lifetimeDays),
            ]));
        });

        DB::afterCommit(static function () use ($invitation, $rawToken): void {
            UserInvited::dispatch($invitation, $rawToken);
        });

        return new InvitationCreated($invitation, $rawToken);
    }
}
