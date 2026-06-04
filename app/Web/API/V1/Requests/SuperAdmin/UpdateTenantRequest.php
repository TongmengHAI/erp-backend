<?php

declare(strict_types=1);

namespace App\Web\API\V1\Requests\SuperAdmin;

use App\Models\Tenant;
use App\Support\Tenancy\Enums\TenantStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /api/v1/super-admin/tenants/{tenant}
 *
 * Partial profile update + status transition. All fields use `sometimes`
 * so the SA can PATCH a single field (e.g. just `status` to suspend).
 *
 * Status transitions allowed in v1:
 *   active   ↔ suspended  (suspend/resume — the v1 SA UX)
 *   archived → DISALLOWED (out of scope per the explicit cuts)
 *
 * Slug is updatable but unique across all tenants (the existing
 * migration constraint); the route-bound Tenant is excluded from the
 * uniqueness check via Rule::unique->ignore.
 */
final class UpdateTenantRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var Tenant $tenant */
        $tenant = $this->route('tenant');

        return [
            'slug' => [
                'sometimes',
                'string',
                'max:63',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('tenants', 'slug')->ignore($tenant->id),
            ],
            'name' => ['sometimes', 'string', 'max:255'],
            'legal_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'country_code' => ['sometimes', 'string', 'regex:/^[A-Z]{2}$/'],
            'default_currency' => ['sometimes', 'string', 'regex:/^[A-Z]{3}$/'],
            'functional_currency' => ['sometimes', 'string', 'regex:/^[A-Z]{3}$/'],
            'timezone' => ['sometimes', 'string', 'max:64'],
            // Status: active | suspended only. archived is out of scope
            // for v1 (no SA UX for hard-archive).
            'status' => [
                'sometimes',
                'string',
                Rule::in([TenantStatus::Active->value, TenantStatus::Suspended->value]),
            ],
        ];
    }
}
