<?php

declare(strict_types=1);

namespace App\Web\API\V1\Requests\HRM;

use App\Domain\HRM\Enums\LeaveType;
use App\Support\Company\CompanyContext;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates input for POST /api/v1/hrm/leave-requests.
 *
 * Notable absences:
 *   - `status` — not in the rules array. New requests always land in
 *     pending; the CreateLeaveRequestAction force-sets it. Even if a
 *     client submits status=approved, validation drops it (unknown key)
 *     AND the Action overwrites.
 *   - `approved_by` / `approved_at` / `approver_note` — same reason. The
 *     only path into terminal states is /approve and /reject endpoints,
 *     gated by the .approve permission.
 *
 * `employee_id` uses the LOAD-BEARING scoped-exists pattern: it must
 * point at a same-tenant, same-company, non-soft-deleted employee. A
 * client submitting a foreign-tenant employee id gets 422, not 201 with
 * an orphan FK. Same shape repeats on every cross-row FK validation in
 * HRM (see Employee.department_id).
 */
class StoreLeaveRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Permission gate is the controller's job (AuthorizesHrmAccess).
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
                    ->where(fn ($q) => $q
                        ->where('tenant_id', $tenantId)
                        ->where('company_id', $companyId)
                        ->whereNull('deleted_at')),
            ],
            'leave_type' => [
                'required',
                'string',
                Rule::in(array_column(LeaveType::cases(), 'value')),
            ],
            'start_date' => ['required', 'date'],
            // end_date >= start_date — mirrored in the DB CHECK and the
            // frontend Zod schema. Three places, one rule, defense in depth.
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
