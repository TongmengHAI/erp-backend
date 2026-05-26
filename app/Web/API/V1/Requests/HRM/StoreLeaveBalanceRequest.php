<?php

declare(strict_types=1);

namespace App\Web\API\V1\Requests\HRM;

use App\Domain\HRM\Enums\LeaveType;
use App\Support\Company\CompanyContext;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates input for POST /api/v1/hrm/leave-balances.
 *
 * Two load-bearing rules:
 *
 *   1. employee_id scoped-exists — same shape as Employee.branch_id
 *      scoped FK. The Rule::exists() where() clause restricts the
 *      lookup to the current (tenant, company); a foreign-context id
 *      surfaces as 422 errors.employee_id rather than silently
 *      persisting a cross-tenant pointer.
 *
 *   2. leave_type restricted to the allocated subset ('annual', 'sick').
 *      Unpaid + other are unbounded by design (no balance row). The
 *      full LeaveType enum stays the source of truth for leave_requests;
 *      this in-rule is the application-layer mirror of the DB CHECK.
 *
 * Unique tuple (tenant_id, company_id, employee_id, leave_type,
 * period_year) is enforced via Rule::unique() with the same partial
 * WHERE deleted_at IS NULL discipline as the DB's partial unique index.
 * A second attempt to create a balance for the same employee+type+year
 * returns 422 errors.leave_type rather than tripping the unique index
 * at INSERT.
 */
class StoreLeaveBalanceRequest extends FormRequest
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
            'employee_id' => [
                'required',
                'integer',
                Rule::exists('employees', 'id')
                    ->where(fn ($q) => $q->where('tenant_id', $tenantId)
                        ->where('company_id', $companyId)
                        ->whereNull('deleted_at')),
            ],
            'leave_type' => [
                'required',
                'string',
                // Allocated subset only. Sending 'unpaid' or 'other'
                // returns 422 errors.leave_type before reaching the DB
                // CHECK. The mismatch between LeaveType (full enum) and
                // this in-rule is deliberate — see the migration's
                // CHECK constraint comment.
                Rule::in([LeaveType::Annual->value, LeaveType::Sick->value]),
                // Composite uniqueness via Rule::unique(). Same partial
                // WHERE deleted_at IS NULL discipline as the DB index.
                Rule::unique('leave_balances', 'leave_type')
                    ->where(fn ($q) => $q->where('tenant_id', $tenantId)
                        ->where('company_id', $companyId)
                        ->where('employee_id', request()->input('employee_id'))
                        ->where('period_year', request()->input('period_year'))
                        ->whereNull('deleted_at')),
            ],
            'period_year' => ['required', 'integer', 'min:2000', 'max:2100'],
            // 0.5-step decimal — matches the half-day granularity of
            // leave requests. Higher cap (366) is generous; real
            // allocations sit between 0 and ~30 for SME tenants.
            'allocated_days' => ['required', 'numeric', 'min:0', 'max:366', 'multiple_of:0.5'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
