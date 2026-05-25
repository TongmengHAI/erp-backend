<?php

declare(strict_types=1);

namespace App\Web\API\V1\Requests\HRM;

use App\Domain\HRM\Enums\LeaveType;
use App\Support\Company\CompanyContext;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /api/v1/hrm/leave-requests/{leaveRequest} input.
 *
 * `sometimes` lets a partial update omit fields entirely. Same notable
 * absences as the Store variant — status and approval columns are not
 * accepted at this layer. The Action layer also rejects the call if the
 * row isn't pending (InvalidLeaveRequestTransitionException, self-renders
 * 422 with error_code='invalid_transition'), so even a tenant-admin user
 * editing an already-decided row gets the transition error rather than a
 * silent partial save.
 */
class UpdateLeaveRequestRequest extends FormRequest
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
                'sometimes',
                'required',
                'integer',
                Rule::exists('employees', 'id')
                    ->where(fn ($q) => $q
                        ->where('tenant_id', $tenantId)
                        ->where('company_id', $companyId)
                        ->whereNull('deleted_at')),
            ],
            'leave_type' => [
                'sometimes',
                'required',
                'string',
                Rule::in(array_column(LeaveType::cases(), 'value')),
            ],
            'start_date' => ['sometimes', 'required', 'date'],
            'end_date' => ['sometimes', 'required', 'date', 'after_or_equal:start_date'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
