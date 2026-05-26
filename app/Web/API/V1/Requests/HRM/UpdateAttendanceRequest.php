<?php

declare(strict_types=1);

namespace App\Web\API\V1\Requests\HRM;

use App\Domain\HRM\Enums\AttendanceStatus;
use App\Domain\HRM\Models\AttendanceRecord;
use App\Domain\HRM\Models\Employee;
use App\Support\Company\CompanyContext;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /api/v1/hrm/attendance/{attendance} input.
 *
 * `sometimes` lets a partial update omit fields entirely. The
 * after() uniqueness check ignores the current record's id so a
 * PATCH that doesn't change (employee_id, date) passes. If either
 * field IS in the payload, the check uses effective values via
 * input-fallback to the existing row.
 */
class UpdateAttendanceRequest extends FormRequest
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
            'date' => ['sometimes', 'required', 'date'],
            'clock_in' => ['sometimes', 'nullable', 'string', 'regex:/^([01]\d|2[0-3]):[0-5]\d:[0-5]\d$/'],
            'clock_out' => ['sometimes', 'nullable', 'string', 'regex:/^([01]\d|2[0-3]):[0-5]\d:[0-5]\d$/'],
            'status' => [
                'sometimes',
                'required',
                'string',
                Rule::in(array_column(AttendanceStatus::cases(), 'value')),
            ],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<int, Closure> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $this->validateUniqueEmployeeDate($validator);
                $this->validateClockOrder($validator);
            },
        ];
    }

    /**
     * Re-check the (employee_id, date) uniqueness using effective
     * post-patch values. Reads input with fallback to the bound
     * model so a PATCH that changes ONLY employee_id (while keeping
     * the existing date) is also caught. Ignore-self via the model's
     * id.
     */
    private function validateUniqueEmployeeDate(Validator $validator): void
    {
        /** @var AttendanceRecord|null $existing */
        $existing = $this->route('attendance');
        if (! $existing) {
            return;
        }

        $effectiveEmployeeId = (int) $this->input('employee_id', $existing->employee_id);
        $effectiveDate = $this->input('date', $existing->date->toDateString());

        $tenantId = app(TenantContext::class)->current()?->id;
        $companyId = app(CompanyContext::class)->current()?->id;

        $conflicts = AttendanceRecord::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('employee_id', $effectiveEmployeeId)
            ->whereDate('date', $effectiveDate)
            ->where('id', '!=', $existing->id)
            ->whereNull('deleted_at')
            ->exists();

        if (! $conflicts) {
            return;
        }

        $employee = Employee::query()->find((int) $effectiveEmployeeId);
        $employeeName = $employee instanceof Employee
            ? $employee->full_name
            : 'this employee';

        $validator->errors()->add(
            'date',
            sprintf(
                'Attendance for %s on %s already exists.',
                $employeeName,
                $effectiveDate,
            ),
        );
    }

    private function validateClockOrder(Validator $validator): void
    {
        /** @var AttendanceRecord|null $existing */
        $existing = $this->route('attendance');
        if (! $existing) {
            return;
        }

        // Read effective values — for the clock-order check, both sides
        // need to be effective post-patch values, OR null (which short-
        // circuits the check). $this->input falls back to the second
        // argument when the key is absent OR when explicitly null in
        // the payload; we want absent → existing, null → null. Use
        // has() to distinguish.
        $in = $this->has('clock_in')
            ? $this->input('clock_in')
            : $existing->clock_in;
        $out = $this->has('clock_out')
            ? $this->input('clock_out')
            : $existing->clock_out;

        if (! $in || ! $out) {
            return;
        }
        if ($out < $in) {
            $validator->errors()->add(
                'clock_out',
                'Clock out must be on or after clock in.',
            );
        }
    }
}
