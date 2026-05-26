<?php

declare(strict_types=1);

namespace App\Web\API\V1\Requests\HRM;

use App\Domain\HRM\Enums\PositionStatus;
use App\Support\Company\CompanyContext;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates input for POST /api/v1/hrm/positions.
 *
 * Mirror of StoreDepartmentRequest. Scoped-unique on code (same
 * pattern as employees.employee_code + departments.code) — partial
 * unique DB index excludes soft-deleted rows so the
 * "delete then re-create with same code" workflow works.
 */
class StorePositionRequest extends FormRequest
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
                Rule::unique('positions', 'code')
                    ->where(fn ($q) => $q->where('tenant_id', $tenantId)
                        ->where('company_id', $companyId)
                        ->whereNull('deleted_at')),
            ],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
            'status' => ['required', 'string', Rule::in(array_column(PositionStatus::cases(), 'value'))],
        ];
    }
}
