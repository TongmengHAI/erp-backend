<?php

declare(strict_types=1);

namespace App\Web\API\V1\Resources\Admin;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\Permission\Models\Role;

/**
 * Brief user payload for /api/v1/admin/users (index list).
 *
 * Narrower than AdminUserResource — list rows render id + name +
 * email + status badge + role name + deactivated flag without
 * needing the full payload. Matches the §10.1 "flat field" pattern
 * for list-row scannability without N+1 (role_name is the flat
 * field; the AdminUserResource carries the nested role snapshot).
 *
 * @mixin User
 */
final class AdminUserBriefResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Role|null $role */
        $role = $this->roles->first();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'status' => $this->status->value,
            'is_active' => $this->isActive(),
            'is_deactivated' => $this->deleted_at !== null,
            'role_name' => $role?->name,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
