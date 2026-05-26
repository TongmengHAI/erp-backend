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
 * Validates input for POST /api/v1/hrm/attendance.
 *
 * The (employee_id, date) uniqueness check runs in after() rather than
 * via Rule::unique() because:
 *   1. The 422 message must name BOTH fields ("Attendance for {employee
 *      name} on {date} already exists.") so the manager isn't ambiguous
 *      about which combination conflicts.
 *   2. The error must surface under the `date` field (date is the more
 *      likely typo — manager picked employee first, then date).
 *   3. Rule::unique's message customization can't natively interpolate
 *      a looked-up employee name; a closure is cleaner than fighting
 *      the rule API.
 *
 * The composite partial unique index in the DB (WHERE deleted_at IS NULL)
 * is the backstop. If the closure ever drifts, the DB constraint surfaces
 * as a 500 — not graceful but not corrupt either.
 *
 * Time fields (clock_in, clock_out) are validated as HH:MM:SS strings
 * matching the Postgres TIME format and the frontend's timeConversion
 * util output. The Zod schema's regex on the frontend uses the same
 * shape: ^([01]\d|2[0-3]):[0-5]\d:[0-5]\d$
 */
class StoreAttendanceRequest extends FormRequest
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
            // Scoped-exists FK — same load-bearing pattern as
            // leave_requests.employee_id. Rejects foreign-tenant /
            // foreign-company / soft-deleted employee_ids with 422.
            'employee_id' => [
                'required',
                'integer',
                Rule::exists('employees', 'id')
                    ->where(fn ($q) => $q
                        ->where('tenant_id', $tenantId)
                        ->where('company_id', $companyId)
                        ->whereNull('deleted_at')),
            ],
            'date' => ['required', 'date'],
            // HH:MM:SS strings matching the Postgres TIME format. The
            // frontend's timeConversion util emits the same shape.
            // Nullable because status=absent / on_leave typically has
            // no clock times. The clock_out >= clock_in invariant is
            // enforced by the DB CHECK + by an after() closure that
            // surfaces a friendlier field error before the DB rejects.
            'clock_in' => ['nullable', 'string', 'regex:/^([01]\d|2[0-3]):[0-5]\d:[0-5]\d$/'],
            'clock_out' => ['nullable', 'string', 'regex:/^([01]\d|2[0-3]):[0-5]\d:[0-5]\d$/'],
            'status' => [
                'required',
                'string',
                Rule::in(array_column(AttendanceStatus::cases(), 'value')),
            ],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * Cross-field validation: uniqueness on (employee, date) AND
     * clock_out >= clock_in when both set. Both checks need access to
     * fully-validated fields (employee_id + date for the uniqueness
     * lookup; both times for the order check), and the uniqueness
     * needs to look up the employee name for the message, so they
     * live here rather than as inline closure rules.
     *
     * @return array<int, Closure>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $this->validateUniqueEmployeeDate($validator);
                $this->validateClockOrder($validator);
            },
        ];
    }

    private function validateUniqueEmployeeDate(Validator $validator): void
    {
        $employeeId = $this->input('employee_id');
        $date = $this->input('date');

        // If either is missing or invalid, the field-level rules already
        // surfaced an error — don't pile on a second one.
        if (! $employeeId || ! $date) {
            return;
        }

        $tenantId = app(TenantContext::class)->current()?->id;
        $companyId = app(CompanyContext::class)->current()?->id;

        $exists = AttendanceRecord::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->where('employee_id', $employeeId)
            ->whereDate('date', $date)
            ->whereNull('deleted_at')
            ->exists();

        if (! $exists) {
            return;
        }

        // Look up the employee name for the message. The employee was
        // already validated as existing in the current (tenant, company),
        // so this query returns a row. If for some reason it doesn't
        // (race condition with a concurrent delete), fall back to a
        // safe placeholder rather than crashing.
        //
        // (int) cast disambiguates Builder::find — without it PHPStan
        // can't narrow the return type to a single model (the
        // overload accepts array<int, int> too).
        $employee = Employee::query()->find((int) $employeeId);
        $employeeName = $employee instanceof Employee
            ? $employee->full_name
            : 'this employee';

        $validator->errors()->add(
            'date',
            sprintf(
                'Attendance for %s on %s already exists.',
                $employeeName,
                $date,
            ),
        );
    }

    private function validateClockOrder(Validator $validator): void
    {
        $in = $this->input('clock_in');
        $out = $this->input('clock_out');
        if (! $in || ! $out) {
            return;
        }
        // String comparison works on HH:MM:SS — lexicographic order
        // matches chronological order within a single day. The DB
        // CHECK enforces this too; this surfaces it as a friendly 422
        // before the request hits the DB.
        if ($out < $in) {
            $validator->errors()->add(
                'clock_out',
                'Clock out must be on or after clock in.',
            );
        }
    }
}
