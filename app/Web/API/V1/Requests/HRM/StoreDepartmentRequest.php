<?php

declare(strict_types=1);

namespace App\Web\API\V1\Requests\HRM;

use App\Domain\HRM\Enums\DepartmentStatus;
use App\Support\Company\CompanyContext;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Permission check is the controller's job (via AuthorizesHrmAccess).
        // Same pattern as StoreEmployeeRequest.
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
                // Scoped uniqueness — same shape as Employee's employee_code rule.
                Rule::unique('departments', 'code')
                    ->where(fn ($q) => $q->where('tenant_id', $tenantId)
                        ->where('company_id', $companyId)
                        ->whereNull('deleted_at')),
            ],
            'name' => ['required', 'string', 'max:255'],
            // Description is bounded at 500 — matches the DB column and the
            // frontend Zod schema. Three places, one cap; drift here would
            // mean the UI accepts characters the API rejects.
            'description' => ['nullable', 'string', 'max:500'],
            'status' => ['required', 'string', Rule::in(array_column(DepartmentStatus::cases(), 'value'))],
        ];
    }
}
