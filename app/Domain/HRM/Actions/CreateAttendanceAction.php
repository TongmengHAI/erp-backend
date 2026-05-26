<?php

declare(strict_types=1);

namespace App\Domain\HRM\Actions;

use App\Domain\HRM\Models\AttendanceRecord;
use Illuminate\Support\Facades\DB;

/**
 * Create a new attendance_record in the current tenant+company context.
 *
 * The uniqueness invariant (one record per employee per date) is
 * enforced at the FormRequest layer via an after() validation closure
 * that surfaces a 422 with the named-fields message
 * "Attendance for {employee name} on {date} already exists." attached
 * to the date field. The composite partial unique index in the DB is
 * the backstop.
 *
 * tenant_id and company_id are auto-filled by the BelongsToTenant +
 * BelongsToCompany traits on `creating` — same pattern as Employee,
 * Department, and LeaveRequest.
 */
final class CreateAttendanceAction
{
    /**
     * @param  array{
     *     employee_id: int,
     *     date: string,
     *     clock_in?: string|null,
     *     clock_out?: string|null,
     *     status: string,
     *     notes?: string|null,
     * }  $data
     */
    public function execute(array $data): AttendanceRecord
    {
        return DB::transaction(function () use ($data): AttendanceRecord {
            $record = new AttendanceRecord;
            $record->fill($data);
            $record->save();

            return $record->refresh();
        });
    }
}
