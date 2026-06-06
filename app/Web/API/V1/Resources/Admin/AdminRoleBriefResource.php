<?php

declare(strict_types=1);

namespace App\Web\API\V1\Resources\Admin;

use App\Domain\Identity\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Brief role payload for /api/v1/admin/roles (list). Mirrors
 * AdminRoleResource minus the heavy fields:
 *   - permissions array (not loaded on the list endpoint)
 *   - description (not shown on the list row)
 *
 * Keeps the same id / name / label / is_system / is_custom /
 * users_count shape so the SPA's list page uses the same row-rendering
 * code as the detail page.
 *
 * @mixin Role
 */
final class AdminRoleBriefResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $isSystem = (bool) $this->is_system;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'label' => $isSystem
                ? __('roles.system.'.$this->name.'.label')
                : $this->name,
            'is_system' => $isSystem,
            'is_custom' => ! $isSystem,
            'users_count' => (int) ($this->users_count ?? 0),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
