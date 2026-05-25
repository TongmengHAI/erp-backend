<?php

declare(strict_types=1);

namespace App\Web\API\V1\Requests\HRM;

use App\Domain\HRM\Enums\EmployeeStatus;
use App\Support\Company\CompanyContext;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Permission check is the controller's job (via AuthorizesHrmAccess).
        // FormRequest::authorize() is an inadequate place for it because the
        // 403 message wouldn't route through our chokepoint pattern.
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->current()?->id;
        $companyId = app(CompanyContext::class)->current()?->id;

        return [
            'employee_code' => [
                'required',
                'string',
                'max:32',
                // Scoped uniqueness — code is unique within (tenant, company)
                // but freely reused across other companies. The composite unique
                // index in the migration mirrors this rule.
                Rule::unique('employees', 'employee_code')
                    ->where(fn ($q) => $q->where('tenant_id', $tenantId)
                        ->where('company_id', $companyId)
                        ->whereNull('deleted_at')),
            ],
            'full_name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'job_title' => ['nullable', 'string', 'max:255'],
            // Department FK — LOAD-BEARING scoped-exists. The where() clause
            // restricts the existence check to departments owned by the
            // current (tenant, company). Without it, a client could submit
            // a foreign-tenant department_id and have it persist — the DB
            // FK alone only checks "does some department with this id
            // exist," not "is it ours." This pattern repeats verbatim for
            // any future cross-module FK (e.g. leave_requests.employee_id).
            'department_id' => [
                'nullable',
                'integer',
                Rule::exists('departments', 'id')
                    ->where(fn ($q) => $q
                        ->where('tenant_id', $tenantId)
                        ->where('company_id', $companyId)
                        ->whereNull('deleted_at')),
            ],
            'hire_date' => ['required', 'date'],
            'status' => ['required', 'string', Rule::in(array_column(EmployeeStatus::cases(), 'value'))],
        ];
    }
}
