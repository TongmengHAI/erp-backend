<?php

declare(strict_types=1);

namespace App\Web\API\V1\Requests\HRM;

use App\Domain\HRM\Enums\DepartmentStatus;
use App\Domain\HRM\Models\Department;
use App\Support\Company\CompanyContext;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDepartmentRequest extends FormRequest
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

        /** @var Department|null $department */
        $department = $this->route('department');
        $departmentId = $department?->id;

        return [
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:32',
                Rule::unique('departments', 'code')
                    ->ignore($departmentId)
                    ->where(fn ($q) => $q->where('tenant_id', $tenantId)
                        ->where('company_id', $companyId)
                        ->whereNull('deleted_at')),
            ],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'status' => ['sometimes', 'required', 'string', Rule::in(array_column(DepartmentStatus::cases(), 'value'))],
        ];
    }
}
