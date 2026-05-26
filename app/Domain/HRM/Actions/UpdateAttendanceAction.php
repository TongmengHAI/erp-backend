<?php

declare(strict_types=1);

namespace App\Domain\HRM\Actions;

use App\Domain\HRM\Models\AttendanceRecord;
use Illuminate\Support\Facades\DB;

/**
 * Update an existing attendance_record. Partial updates supported via
 * the FormRequest's `sometimes` rules — only fields present in $data
 * are touched.
 *
 * tenant_id and company_id are NOT mutable (request validation doesn't
 * accept them; the traits only auto-fill on `creating`). employee_id
 * + date are technically mutable but the FormRequest's after() closure
 * re-checks the (employee, date) uniqueness with ignore-self when
 * either field is in the payload — same shape as the StoreRequest's
 * uniqueness check.
 */
final class UpdateAttendanceAction
{
    /**
     * @param  array{
     *     employee_id?: int,
     *     date?: string,
     *     clock_in?: string|null,
     *     clock_out?: string|null,
     *     status?: string,
     *     notes?: string|null,
     * }  $data
     */
    public function execute(AttendanceRecord $record, array $data): AttendanceRecord
    {
        return DB::transaction(function () use ($record, $data): AttendanceRecord {
            $record->fill($data);
            $record->save();

            return $record->refresh();
        });
    }
}
