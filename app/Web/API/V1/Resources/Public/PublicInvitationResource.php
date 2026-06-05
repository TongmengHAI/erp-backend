<?php

declare(strict_types=1);

namespace App\Web\API\V1\Resources\Public;

use App\Domain\Identity\Models\Invitation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\Permission\Models\Role;

/**
 * Public-facing invitation payload for GET /api/v1/invitations/{token}.
 *
 * This is the preview the invitee sees before they enter a password.
 * Per Q4: show invitee their context (tenant name + role name +
 * inviter name) so they understand what they're accepting.
 *
 * Hides every identifier and timestamp that doesn't belong on a
 * public preview — no IDs, no tenant_id, no role_id (only the names).
 * Hides token_hash (already on $hidden) and accepted_user_id /
 * cancelled_by_user_id (irrelevant to the invitee).
 *
 * Hides the invitation `id` deliberately. The invitee never needs it;
 * exposing it would let an attacker holding a valid token enumerate
 * adjacent invitations.
 *
 * @mixin Invitation
 */
final class PublicInvitationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $tenant = $this->tenant;
        $role = Role::query()->find($this->role_id);
        $inviter = $this->invitedBy;

        return [
            'email' => $this->email,
            'name' => $this->name,
            'tenant' => $tenant !== null
                ? ['name' => $tenant->name, 'slug' => $tenant->slug]
                : null,
            'role_name' => $role?->name,
            'invited_by_name' => $inviter?->name,
            'expires_at' => $this->expires_at->toIso8601String(),
        ];
    }
}
