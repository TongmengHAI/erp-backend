<?php

declare(strict_types=1);

namespace App\Web\API\V1\Resources\HRM;

use App\Domain\HRM\Models\AttendanceRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Compact AttendanceRecord shape — used in list (index) responses.
 *
 * Flattens employee_name + employee_code to top-level fields so the
 * DataTable cell renders without traversing a nested object. Drops
 * notes + created_at + updated_at; the detail page surfaces those
 * if needed.
 *
 * employee_name / employee_code are null when the parent Employee
 * row was soft-deleted (see LeaveRequest.brief precedent — same
 * soft-delete nullability discipline the user flagged after the
 * PHPStan catch on LeaveRequest.$employee).
 *
 * @mixin AttendanceRecord
 */
class AttendanceRecordBriefResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'employee_name' => $this->employee?->full_name,
            'employee_code' => $this->employee?->employee_code,
            'date' => $this->date->toDateString(),
            'clock_in' => $this->clock_in,
            'clock_out' => $this->clock_out,
            'status' => $this->status->value,
        ];
    }
}
