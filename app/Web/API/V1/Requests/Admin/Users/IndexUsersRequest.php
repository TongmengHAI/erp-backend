<?php

declare(strict_types=1);

namespace App\Web\API\V1\Requests\Admin\Users;

use App\Support\Identity\Enums\UserStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates query input for GET /api/v1/admin/users.
 *
 * Filters:
 *   • status         — optional, must be a valid UserStatus enum value.
 *   • include_deactivated — optional bool. Default: false (list excludes
 *                            soft-deleted rows). When true, the controller
 *                            applies withTrashed().
 *   • search         — optional string. Case-insensitive ILIKE match on
 *                       name + email.
 *   • role_id        — optional integer FK to roles.id.
 *   • per_page       — optional int 1..100. Default 15.
 *
 * Authorization is handled by the controller (AuthorizesUserManagement
 * trait) — this FormRequest only validates shape.
 */
final class IndexUsersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $statusValues = array_map(static fn (UserStatus $s): string => $s->value, UserStatus::cases());

        return [
            'status' => ['nullable', 'string', Rule::in($statusValues)],
            'include_deactivated' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:255'],
            'role_id' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
