<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Events\UserInvited;
use App\Domain\Identity\Exceptions\InvalidInvitationException;
use App\Domain\Identity\Models\Invitation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Re-send an invitation: soft-delete the existing pending row + create
 * a fresh one with a new token and a new expires_at. The old token
 * URL becomes structurally invalid (the soft-deleted row's hash is
 * still in the DB but SoftDeletes excludes it from the accept lookup).
 *
 * Why soft-delete-and-recreate rather than mutate-in-place:
 *   • Audit history of every invitation attempt preserved as a
 *     separate row, each with its own created/cancelled/accepted
 *     timeline.
 *   • The partial unique index
 *     invitations_active_per_tenant_email_uniq is naturally
 *     satisfied — the old row leaves the predicate's WHERE clause
 *     when deleted_at is set; the new row enters cleanly.
 *   • No mutation of the original row means no audit-row mismatch
 *     between "the invitation that was sent" and "the invitation that
 *     was accepted".
 *
 * Refuses to resend already-accepted invitations — the user account
 * exists; what would you resend? Cancelled invitations CAN be resent
 * (the admin reconsidered and re-issues).
 *
 * Returns InvitationCreated with the fresh row and the new raw token.
 */
final class ResendInvitationAction
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    public function execute(Invitation $existing, int $actorId, int $lifetimeDays): InvitationCreated
    {
        if ($existing->accepted_at !== null) {
            throw InvalidInvitationException::accepted();
        }

        $rawToken = Invitation::generateRawToken();

        $newInvitation = DB::transaction(function () use ($existing, $actorId, $rawToken, $lifetimeDays): Invitation {
            return $this->tenantContext->asSystem(function () use ($existing, $actorId, $rawToken, $lifetimeDays): Invitation {
                // Soft-delete the old row. Auditable's writeAuditOnDeleted
                // fires with action='soft_deleted'. No mutation of the
                // existing token_hash / expires_at — the row is preserved
                // as-is at the moment of resend.
                $existing->delete();

                return Invitation::query()->create([
                    'tenant_id' => $existing->tenant_id,
                    'email' => $existing->email,
                    'name' => $existing->name,
                    'role_id' => $existing->role_id,
                    'token_hash' => Invitation::hashToken($rawToken),
                    'invited_by_user_id' => $actorId,
                    'expires_at' => now()->addDays($lifetimeDays),
                ]);
            });
        });

        DB::afterCommit(static function () use ($newInvitation, $rawToken): void {
            UserInvited::dispatch($newInvitation, $rawToken);
        });

        return new InvitationCreated($newInvitation, $rawToken);
    }
}
