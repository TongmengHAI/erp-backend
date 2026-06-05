<?php

declare(strict_types=1);

namespace App\Web\API\V1\Resources\Admin;

use App\Domain\Identity\Models\Invitation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full invitation payload for admin/users/invitations endpoints
 * (store, cancel, resend, show).
 *
 * Carries the computed status (resolved by Invitation::status() —
 * the InvitationQueryService selects the same value via SQL CASE
 * WHEN, so list endpoints can use either source consistently).
 *
 * Hides token_hash (already on $hidden in the model; double-defense).
 *
 * @mixin Invitation
 */
final class AdminInvitationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'name' => $this->name,
            'role_id' => $this->role_id,
            'status' => $this->status()->value,
            'expires_at' => $this->expires_at->toIso8601String(),
            'accepted_at' => $this->accepted_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            'invited_by_user_id' => $this->invited_by_user_id,
        ];
    }
}
