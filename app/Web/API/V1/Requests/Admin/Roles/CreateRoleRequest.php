<?php

declare(strict_types=1);

namespace App\Web\API\V1\Requests\Admin\Roles;

use App\Models\User;
use Database\Seeders\Framework\DefaultRolesSeeder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates POST /api/v1/admin/roles (create custom role).
 *
 * Triple-stack discipline per §10.4:
 *   - System role name collision rejected here at the API boundary
 *     (the database's partial unique indexes won't fire — a custom
 *     row with name='tenant_admin' is technically permissible at the
 *     DB layer since the two partial indexes are mutually exclusive
 *     on is_system).
 *   - Custom-role-per-tenant uniqueness rejected here too — the
 *     partial unique index is the DB backstop.
 *   - Frontend Zod schema (Session 4) carries the same constraints.
 *
 * Authorization: caller must have roles.create. The controller's
 * authorizeRolesAction() handles the 403; FormRequest only validates
 * shape.
 */
final class CreateRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        /** @var User|null $actor */
        $actor = $this->user();
        $tenantId = $actor?->tenant_id;

        $systemRoleNames = [
            DefaultRolesSeeder::SYSTEM_ROLE_NAME_TENANT_ADMIN,
            DefaultRolesSeeder::SYSTEM_ROLE_NAME_ACCOUNTANT,
            DefaultRolesSeeder::SYSTEM_ROLE_NAME_VIEWER,
        ];

        return [
            'name' => [
                'required',
                'string',
                'min:1',
                'max:255',
                // System role name collision — application-layer rejection
                // ahead of the DB's partial indexes. System rows have
                // team_id=NULL so the partial roles_custom_name_per_tenant
                // _uniq doesn't catch this — the FormRequest is the
                // canonical guard.
                Rule::notIn($systemRoleNames),
                // Per-tenant custom-role uniqueness. Backed by the partial
                // unique index roles_custom_name_per_tenant_uniq (DB
                // layer's triple-stack contribution).
                Rule::unique('roles', 'name')
                    ->where(fn ($q) => $q
                        ->where('team_id', $tenantId)
                        ->where('is_system', false)
                        ->whereNull('deleted_at')
                    ),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'permission_ids' => ['present', 'array'],
            'permission_ids.*' => ['integer', Rule::exists('permissions', 'id')],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.not_in' => 'This role name is reserved by the system. Choose a different name.',
            'name.unique' => 'You already have a custom role with this name.',
        ];
    }
}
