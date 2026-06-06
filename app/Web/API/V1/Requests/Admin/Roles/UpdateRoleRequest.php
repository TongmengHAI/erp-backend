<?php

declare(strict_types=1);

namespace App\Web\API\V1\Requests\Admin\Roles;

use App\Models\User;
use Database\Seeders\Framework\DefaultRolesSeeder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates PATCH /api/v1/admin/roles/{role} (update custom role).
 *
 * Differs from CreateRoleRequest in two ways:
 *   - All fields are 'sometimes' (partial update).
 *   - The unique check IGNORES the row being updated (otherwise a
 *     no-op rename would 422 against itself).
 *
 * System-role mutation is rejected at the Action layer via
 * RoleImmutableException (defense-in-depth — the FormRequest can't
 * know is_system without an extra query, so the layered guard pattern
 * lives in the Action).
 *
 * Authorization: caller must have roles.update. The controller's
 * authorizeRolesAction() handles the 403.
 */
final class UpdateRoleRequest extends FormRequest
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

        $roleId = (int) $this->route('role');

        $systemRoleNames = [
            DefaultRolesSeeder::SYSTEM_ROLE_NAME_TENANT_ADMIN,
            DefaultRolesSeeder::SYSTEM_ROLE_NAME_ACCOUNTANT,
            DefaultRolesSeeder::SYSTEM_ROLE_NAME_VIEWER,
        ];

        return [
            'name' => [
                'sometimes',
                'string',
                'min:1',
                'max:255',
                Rule::notIn($systemRoleNames),
                Rule::unique('roles', 'name')
                    ->ignore($roleId)
                    ->where(fn ($q) => $q
                        ->where('team_id', $tenantId)
                        ->where('is_system', false)
                        ->whereNull('deleted_at')
                    ),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'permission_ids' => ['sometimes', 'array'],
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
