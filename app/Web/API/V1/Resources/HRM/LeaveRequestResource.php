<?php

declare(strict_types=1);

namespace App\Web\API\V1\Resources\HRM;

use App\Domain\HRM\Models\LeaveRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full-shape LeaveRequest — used in show / store / update / approve /
 * reject responses.
 *
 * Approval block is null when the request is still pending. When
 * decided, it carries:
 *   - approved_at (ISO8601 instant of the decision)
 *   - approver: nested {id, name} snapshot, OR null if the approver
 *     user was hard-deleted (FK is ON DELETE SET NULL — the decision
 *     stands without an attributed actor; the audit log preserves the
 *     full actor history regardless).
 *   - note (optional manager comment)
 *
 * Naming: the block is called `approval` regardless of whether the
 * decision was approve or reject. The status enum disambiguates; the
 * decision-metadata block is shape-symmetric so the SPA renders one
 * "decided by Manager User on 2026-05-24" panel for both terminal states.
 *
 * @mixin LeaveRequest
 */
class LeaveRequestResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // Nested employee snapshot — same projection pattern as
            // EmployeeResource.department: flat array, not a recursive
            // resource, to keep payload bounded.
            'employee' => $this->employee
                ? [
                    'id' => $this->employee->id,
                    'employee_code' => $this->employee->employee_code,
                    'full_name' => $this->employee->full_name,
                ]
                : null,
            'leave_type' => $this->leave_type->value,
            'start_date' => $this->start_date->toDateString(),
            'end_date' => $this->end_date->toDateString(),
            'reason' => $this->reason,
            'status' => $this->status->value,
            // Approval block — present only when decided. The composite DB
            // CHECK guarantees: status<>'pending' ⇒ approved_by AND
            // approved_at are NOT NULL. So inside this branch both are
            // safe to dereference. approver may still be null if the user
            // was hard-deleted (ON DELETE SET NULL on the FK).
            'approval' => $this->approved_at
                ? [
                    'approved_at' => $this->approved_at->toIso8601String(),
                    'approver' => $this->approver
                        ? [
                            'id' => $this->approver->id,
                            'name' => $this->approver->name,
                        ]
                        : null,
                    'note' => $this->approver_note,
                ]
                : null,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
