<?php

declare(strict_types=1);

namespace App\Domain\HRM\Actions;

use App\Domain\HRM\Enums\LeaveRequestStatus;
use App\Domain\HRM\Models\LeaveRequest;
use Illuminate\Support\Facades\DB;

/**
 * Create a new leave_request in the current tenant+company context.
 *
 * Forces status='pending' regardless of what the caller submits. The
 * StoreLeaveRequestRequest doesn't validate `status` (it's not in the
 * rules array), and even if a client submits one, this Action drops
 * it. New requests always land in the pending state — the only path
 * to approved or rejected is through the dedicated transition Actions.
 *
 * Belt and suspenders:
 *   - The DB column defaults to 'pending'
 *   - This Action overwrites with Pending explicitly
 *   - The composite DB CHECK forbids any other status from coexisting
 *     with null approval columns
 *
 * tenant_id and company_id are auto-filled by the BelongsToTenant +
 * BelongsToCompany traits on `creating` — same pattern as Employee
 * and Department.
 */
final class CreateLeaveRequestAction
{
    /**
     * @param  array{
     *     employee_id: int,
     *     leave_type: string,
     *     start_date: string,
     *     end_date: string,
     *     reason?: string|null,
     * }  $data
     */
    public function execute(array $data): LeaveRequest
    {
        return DB::transaction(function () use ($data): LeaveRequest {
            $request = new LeaveRequest;
            $request->fill($data);
            // Force the workflow's entry state. Even if a client submits
            // status=approved, the audit row will show 'created' with
            // after.status='pending' — no path to skip the workflow.
            $request->status = LeaveRequestStatus::Pending;
            $request->approved_by = null;
            $request->approved_at = null;
            $request->approver_note = null;
            $request->save();

            return $request->refresh();
        });
    }
}
