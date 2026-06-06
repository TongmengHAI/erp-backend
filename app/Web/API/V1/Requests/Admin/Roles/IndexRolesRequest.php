<?php

declare(strict_types=1);

namespace App\Web\API\V1\Requests\Admin\Roles;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates query input for GET /api/v1/admin/roles.
 *
 * Filters:
 *   • kind        — optional. 'system' | 'custom'. When omitted the
 *                   index returns BOTH (system rows first, then the
 *                   tenant's custom rows).
 *   • search      — optional. ILIKE match on name + description.
 *   • per_page    — optional int 1..100. Default 25 (roles lists are
 *                   typically short — 25 fits a single page on most
 *                   tenants).
 */
final class IndexRolesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'kind' => ['nullable', 'string', Rule::in(['system', 'custom'])],
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
