<?php

declare(strict_types=1);

namespace App\Web\API\V1\Resources\HRM;

use App\Domain\HRM\Models\LeaveRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Compact LeaveRequest shape — used in list (index) responses.
 *
 * Drops the full approval block (just exposes a flat approved_at +
 * approver_name) and drops reason/created_at/updated_at to keep list
 * rows small. The detail page fetches the full resource.
 *
 * employee_name (not _code) keeps the list row scannable. Eager-loaded
 * via with(['employee', 'approver']) in the controller's index query —
 * no N+1.
 *
 * @mixin LeaveRequest
 */
class LeaveRequestBriefResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'employee_name' => $this->employee?->full_name,
            'employee_code' => $this->employee?->employee_code,
            'leave_type' => $this->leave_type->value,
            'start_date' => $this->start_date->toDateString(),
            'end_date' => $this->end_date->toDateString(),
            // List shape carries day_part so the Dates column can adapt
            // ("Fri, May 22 (Morning)" vs "Fri, May 22 → Fri, May 26")
            // without an extra detail fetch per row.
            'day_part' => $this->day_part->value,
            'status' => $this->status->value,
            // Flat approval fields on the list shape — easier for the
            // table column ("Decided by: Manager User") than a nested
            // object. Both fields are null for pending rows.
            'approved_at' => $this->approved_at?->toIso8601String(),
            'approver_name' => $this->approver?->name,
        ];
    }
}
