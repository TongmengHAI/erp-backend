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
 *   • lifecycle      — preferred, UI-aligned. One of:
 *                        active       → status=active AND not deactivated
 *                        inactive     → status=inactive AND not deactivated
 *                        deactivated  → deleted_at IS NOT NULL (status irrelevant)
 *                       Takes precedence over status + include_deactivated
 *                       when set.
 *   • status         — legacy, must be a valid UserStatus enum value.
 *                       Use lifecycle for new callers.
 *   • include_deactivated — legacy bool. Default: false (list excludes
 *                            soft-deleted rows). When true, the controller
 *                            applies withTrashed(). Use lifecycle=deactivated
 *                            for the "only deactivated" filter.
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
        // lifecycle = UI-aligned superset of UserStatus. 'deactivated'
        // isn't a UserStatus value (it's a soft-delete position), but
        // the filter surface uses it as a sibling for UI parity. The
        // frontend's USER_LIFECYCLE_FILTERS frozen const mirrors these
        // three values exactly.
        $lifecycleValues = [...$statusValues, 'deactivated'];

        return [
            'lifecycle' => ['nullable', 'string', Rule::in($lifecycleValues)],
            'status' => ['nullable', 'string', Rule::in($statusValues)],
            'include_deactivated' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:255'],
            'role_id' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
