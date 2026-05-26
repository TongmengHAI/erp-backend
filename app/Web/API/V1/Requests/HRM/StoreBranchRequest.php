<?php

declare(strict_types=1);

namespace App\Web\API\V1\Requests\HRM;

use App\Domain\HRM\Enums\BranchStatus;
use App\Support\Company\CompanyContext;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates input for POST /api/v1/hrm/branches.
 *
 * Mirror of StorePositionRequest with three additional fields
 * (address, city, country_code, phone). country_code uses the
 * ^[A-Z]{2}$ regex — the FormRequest is the only ingestion path
 * in v1; the DB has no matching CHECK. If future paths (CSV
 * import, etc.) bypass this layer they MUST either route through
 * a FormRequest or add a DB CHECK — surfaced in hrm.md.
 */
class StoreBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->current()?->id;
        $companyId = app(CompanyContext::class)->current()?->id;

        return [
            'code' => [
                'required',
                'string',
                'max:32',
                Rule::unique('branches', 'code')
                    ->where(fn ($q) => $q->where('tenant_id', $tenantId)
                        ->where('company_id', $companyId)
                        ->whereNull('deleted_at')),
            ],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
            // Physical-location fields — all nullable.
            'address' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:100'],
            // ISO 3166-1 alpha-2 — bounded regex matches the
            // varchar(2) column AND rejects mixed case / non-ASCII
            // garbage. The seeder uses 'KH' uppercase.
            'country_code' => ['nullable', 'string', 'regex:/^[A-Z]{2}$/'],
            'phone' => ['nullable', 'string', 'max:32'],
            'status' => ['required', 'string', Rule::in(array_column(BranchStatus::cases(), 'value'))],
        ];
    }
}
