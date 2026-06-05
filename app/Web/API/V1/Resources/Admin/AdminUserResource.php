<?php

declare(strict_types=1);

namespace App\Web\API\V1\Resources\Admin;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\Permission\Models\Role;

/**
 * Full user payload for /api/v1/admin/users/{id} (detail) +
 * transition endpoints (disable, enable, deactivate, restore).
 *
 * Separate from the auth-side UserResource (which serves /auth/login
 * and /auth/me) so admin-specific fields (status, deleted_at, role
 * snapshot) can grow without affecting the auth response shape.
 *
 * Role snapshot: returns the first tenant-scoped role's name. Phase
 * 2A users have exactly one role assigned to them per tenant; the
 * snapshot is narrow (id + name) per CLAUDE.md §10.1 cross-module FK
 * nested-snapshot pattern.
 *
 * @mixin User
 */
final class AdminUserResource extends JsonResource
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
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'type' => $this->type->value,
            'status' => $this->status->value,
            'is_super_admin' => $this->isSuperAdmin(),
            'is_active' => $this->isActive(),
            'is_deactivated' => $this->deleted_at !== null,
            'deleted_at' => $this->deleted_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'role' => $role !== null
                ? ['id' => $role->id, 'name' => $role->name]
                : null,
        ];
    }
}
