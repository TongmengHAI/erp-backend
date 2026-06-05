<?php

declare(strict_types=1);

namespace App\Web\API\V1\Resources\Admin;

use App\Domain\Identity\Models\Invitation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Brief invitation payload for GET /admin/users/invitations (list).
 *
 * Reads status from the SQL-selected `status_computed` column when
 * routed through InvitationQueryService — falls back to the model's
 * status() accessor so the resource works either way.
 *
 * @mixin Invitation
 */
final class AdminInvitationBriefResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        // status_computed is selected by InvitationQueryService for list
        // queries; falls back to the model accessor for single-row
        // fetches that don't go through the query service.
        $status = $this->getAttribute('status_computed') ?? $this->status()->value;

        return [
            'id' => $this->id,
            'email' => $this->email,
            'name' => $this->name,
            'status' => $status,
            'expires_at' => $this->expires_at->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
