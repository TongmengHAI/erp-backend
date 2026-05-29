<?php

declare(strict_types=1);

namespace App\Web\API\V1\Requests\HRM;

use App\Domain\HRM\Enums\EmployeeStatus;
use App\Domain\HRM\Services\HrmSettingsRepository;
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

        // Settings-driven employee_code rule: when auto-gen is on,
        // the field is PROHIBITED (server generates; client must not
        // send a value). When off, it's REQUIRED (current behavior).
        // The repository's per-request cache means CreateEmployeeAction
        // reads the same row from memory, no second query.
        $settings = app(HrmSettingsRepository::class)->getForCurrentCompany();
        $employeeCodeRule = $settings->auto_generate_employee_code
            ? ['prohibited']
            : [
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
            ];

        return [
            'employee_code' => $employeeCodeRule,
            'full_name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            // Department FK — LOAD-BEARING scoped-exists. The where() clause
            // restricts the existence check to departments owned by the
            // current (tenant, company). Without it, a client could submit
            // a foreign-tenant department_id and have it persist — the DB
            // FK alone only checks "does some department with this id
            // exist," not "is it ours." This pattern repeats verbatim for
            // every cross-module FK in the codebase (position_id below,
            // leave_requests.employee_id, attendance_records.employee_id).
            'department_id' => [
                'nullable',
                'integer',
                Rule::exists('departments', 'id')
                    ->where(fn ($q) => $q
                        ->where('tenant_id', $tenantId)
                        ->where('company_id', $companyId)
                        ->whereNull('deleted_at')),
            ],
            // Position FK — same load-bearing scoped-exists pattern.
            // Replaces the old free-text job_title field (dropped in the
            // Positions slice). Foreign-tenant or foreign-company position
            // ids get 422 errors.position_id.
            'position_id' => [
                'nullable',
                'integer',
                Rule::exists('positions', 'id')
                    ->where(fn ($q) => $q
                        ->where('tenant_id', $tenantId)
                        ->where('company_id', $companyId)
                        ->whereNull('deleted_at')),
            ],
            // Branch FK — same scoped-exists pattern again. Third
            // cross-module FK on Employee; the shape is mechanical.
            'branch_id' => [
                'nullable',
                'integer',
                Rule::exists('branches', 'id')
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
