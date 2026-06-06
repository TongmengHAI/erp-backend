<?php

declare(strict_types=1);

namespace App\Web\API\V1\Requests\Admin\Roles;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates GET /api/v1/admin/roles/{role}/impact?removed_permissions[]=...
 *
 * The endpoint computes the user-impact preview the SPA renders
 * before saving a permission removal. Per the over-warn semantic
 * (documented in RoleImpactController + RoleImpactService), the
 * removed_permissions array is accepted but the count itself doesn't
 * filter by it — the count is "users currently assigned this role."
 * The parameter is kept so the contract supports a future "advanced
 * impact" endpoint without a route change.
 */
final class RoleImpactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'removed_permissions' => ['present', 'array'],
            'removed_permissions.*' => ['string', Rule::exists('permissions', 'name')],
        ];
    }
}
