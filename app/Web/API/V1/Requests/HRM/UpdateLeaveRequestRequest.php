<?php

declare(strict_types=1);

namespace App\Web\API\V1\Requests\HRM;

use App\Domain\HRM\Enums\DayPart;
use App\Domain\HRM\Enums\LeaveType;
use App\Domain\HRM\Models\LeaveRequest;
use App\Support\Company\CompanyContext;
use App\Support\Tenancy\TenantContext;
use Closure;
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
            // The conditional rule needs to consider the EFFECTIVE values
            // post-patch, not just what's in this request. A PATCH that
            // only submits day_part='morning' on a row whose existing
            // dates differ would otherwise slip through. Read the
            // existing row from route-binding and fall back to its
            // values for anything not in this request body.
            'end_date' => [
                'sometimes',
                'required',
                'date',
                'after_or_equal:start_date',
                function (string $attribute, mixed $value, Closure $fail): void {
                    /** @var LeaveRequest|null $existing */
                    $existing = $this->route('leaveRequest');
                    $effectiveDayPart = $this->input(
                        'day_part',
                        $existing?->day_part->value ?? DayPart::FullDay->value,
                    );
                    $effectiveStart = $this->input(
                        'start_date',
                        $existing?->start_date->toDateString(),
                    );
                    if ($effectiveDayPart !== DayPart::FullDay->value
                        && $value !== $effectiveStart) {
                        $fail('A half-day request must start and end on the same date.');
                    }
                },
            ],
            'day_part' => [
                'sometimes',
                'string',
                Rule::in(array_column(DayPart::cases(), 'value')),
                // Mirror the end_date check from the other direction:
                // a PATCH that only changes day_part (without changing
                // dates) on a row with mismatched dates must also fail.
                // Without this, a payload like {"day_part": "morning"}
                // on a multi-day pending row would pass the end_date
                // rule (end_date not in payload) and only fail at the
                // DB CHECK — which works, but surfaces as a 500 instead
                // of a 422 with a friendly field error.
                function (string $attribute, mixed $value, Closure $fail): void {
                    /** @var LeaveRequest|null $existing */
                    $existing = $this->route('leaveRequest');
                    $effectiveStart = $this->input(
                        'start_date',
                        $existing?->start_date->toDateString(),
                    );
                    $effectiveEnd = $this->input(
                        'end_date',
                        $existing?->end_date->toDateString(),
                    );
                    if ($value !== DayPart::FullDay->value
                        && $effectiveStart !== $effectiveEnd) {
                        $fail('A half-day request must start and end on the same date.');
                    }
                },
            ],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
