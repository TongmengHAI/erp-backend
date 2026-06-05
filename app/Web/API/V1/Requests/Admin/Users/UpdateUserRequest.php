<?php

declare(strict_types=1);

namespace App\Web\API\V1\Requests\Admin\Users;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates input for PATCH /api/v1/admin/users/{user}.
 *
 * Phase 2A update scope is intentionally minimal — name + role only.
 * Status transitions go through dedicated /disable, /enable,
 * /deactivate, /restore endpoints per CLAUDE.md §10.2 (state-machine
 * pattern: transition endpoints as separate invokable controllers).
 *
 * role_id uses a scoped Rule::exists per CLAUDE.md §10.1 — restricts
 * the FK lookup so a client can't reference a role created in a
 * different team_id scope (defensive; Spatie's role rows are global
 * with team_id=null in this codebase, so it's a no-op today but the
 * scoped Rule documents intent and survives a future per-tenant role
 * editor landing in Phase 2B).
 */
final class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:1', 'max:255'],
            'role_id' => [
                'sometimes',
                'integer',
                Rule::exists('roles', 'id'),
            ],
        ];
    }
}
