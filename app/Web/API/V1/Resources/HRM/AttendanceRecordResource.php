<?php

declare(strict_types=1);

namespace App\Web\API\V1\Resources\HRM;

use App\Domain\HRM\Models\AttendanceRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full-shape AttendanceRecord — used in show / store / update responses.
 *
 * Nested employee snapshot — flat array projection, three fields only,
 * same pattern as LeaveRequest.employee. Null when the parent Employee
 * row was soft-deleted (belongsTo respects SoftDeletes on the parent).
 *
 * clock_in / clock_out are HH:MM:SS strings, matching the Postgres
 * TIME column wire format and the frontend's timeConversion util.
 *
 * @mixin AttendanceRecord
 */
class AttendanceRecordResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee' => $this->employee
                ? [
                    'id' => $this->employee->id,
                    'employee_code' => $this->employee->employee_code,
                    'full_name' => $this->employee->full_name,
                ]
                : null,
            'date' => $this->date->toDateString(),
            'clock_in' => $this->clock_in,
            'clock_out' => $this->clock_out,
            'status' => $this->status->value,
            'notes' => $this->notes,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
