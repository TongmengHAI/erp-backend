<?php

declare(strict_types=1);

namespace App\Web\API\V1\Resources\Admin;

use App\Domain\Identity\Models\Role;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full role payload for /api/v1/admin/roles/{id} (detail) +
 * create/update responses.
 *
 * Display labels:
 *   - System roles: i18n keys (resources/lang/en/roles.php) for label
 *     + description. The 'label' field carries the rendered value so
 *     the SPA doesn't need a separate __() pass.
 *   - Custom roles: the row's own `name` (admin-entered) + `description`.
 *     The 'label' field carries the row's `name` verbatim — no
 *     translation lookup, no decoration.
 *
 * is_system + is_custom are both surfaced for SPA convenience (single
 * boolean check instead of !is_system). The pair is mutually exclusive
 * by construction.
 *
 * users_count is the LIVE count from model_has_roles for this role;
 * it's the same value RoleInUseException uses for its 422 response.
 * SPA shows this on the list page + on the detail page so admins
 * understand impact before clicking Delete.
 *
 * @mixin Role
 */
final class AdminRoleResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $isSystem = (bool) $this->is_system;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $isSystem
                ? __('roles.system.'.$this->name.'.description')
                : $this->description,
            'label' => $isSystem
                ? __('roles.system.'.$this->name.'.label')
                : $this->name,
            'is_system' => $isSystem,
            'is_custom' => ! $isSystem,
            'team_id' => $this->team_id,
            'is_deleted' => $this->deleted_at !== null,
            'permissions' => $this->whenLoaded('permissions', function () {
                return $this->permissions->map(static fn (Model $p): array => [
                    'id' => (int) $p->getKey(),
                    'name' => (string) $p->getAttribute('name'),
                ])->values()->all();
            }),
            'users_count' => (int) ($this->users_count ?? 0),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
