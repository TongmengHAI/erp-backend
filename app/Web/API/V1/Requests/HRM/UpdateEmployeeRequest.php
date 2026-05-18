<?php

declare(strict_types=1);

namespace App\Web\API\V1\Requests\HRM;

use App\Domain\HRM\Enums\EmployeeStatus;
use App\Domain\HRM\Models\Employee;
use App\Support\Company\CompanyContext;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmployeeRequest extends FormRequest
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

        /** @var Employee|null $employee */
        $employee = $this->route('employee');
        $employeeId = $employee?->id;

        return [
            'employee_code' => [
                'sometimes',
                'required',
                'string',
                'max:32',
                Rule::unique('employees', 'employee_code')
                    ->ignore($employeeId)
                    ->where(fn ($q) => $q->where('tenant_id', $tenantId)
                        ->where('company_id', $companyId)
                        ->whereNull('deleted_at')),
            ],
            'full_name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'string', 'email', 'max:255'],
            'job_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'hire_date' => ['sometimes', 'required', 'date'],
            'status' => ['sometimes', 'required', 'string', Rule::in(array_column(EmployeeStatus::cases(), 'value'))],
        ];
    }
}
