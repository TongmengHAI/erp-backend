<?php

declare(strict_types=1);

namespace App\Web\API\V1\Controllers\Public;

use App\Domain\Identity\Exceptions\InvalidInvitationException;
use App\Domain\Identity\Models\Invitation;
use App\Support\Tenancy\TenantContext;
use App\Web\API\V1\Controllers\Controller;
use App\Web\API\V1\Resources\Public\PublicInvitationResource;
use Illuminate\Http\Request;

/**
 * GET /api/v1/invitations/{token}.
 *
 * Public endpoint — no auth context. The invitee's browser hits this
 * when landing on /invitation/:token to fetch the preview
 * (tenant name, role name, inviter name, expires_at) for the public
 * AcceptInvitationPage form (Session 5).
 *
 * Validates the same 4 state cases as AcceptInvitationAction —
 * token_invalid / expired / cancelled / accepted — so the SPA can
 * render the appropriate InvitationInvalidPage variant without
 * speculatively submitting the password form.
 *
 * No rate limit on this endpoint in Phase 2A — the token is high-
 * entropy (256 bits via Str::random(43)). A future hardening pass
 * may add a per-IP limit if scraping becomes a concern.
 */
final class ShowInvitationController extends Controller
{
    public function __invoke(
        Request $request,
        string $token,
        TenantContext $tenantContext,
    ): PublicInvitationResource {
        $hash = Invitation::hashToken($token);

        $invitation = $tenantContext->asSystem(
            static fn (): ?Invitation => Invitation::query()->where('token_hash', $hash)->first()
        );

        if ($invitation === null) {
            throw InvalidInvitationException::tokenInvalid();
        }

        if ($invitation->accepted_at !== null) {
            throw InvalidInvitationException::accepted();
        }
        if ($invitation->cancelled_at !== null) {
            throw InvalidInvitationException::cancelled();
        }
        if ($invitation->expires_at->isPast()) {
            throw InvalidInvitationException::expired();
        }

        return new PublicInvitationResource($invitation);
    }
}
