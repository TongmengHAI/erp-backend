<?php

declare(strict_types=1);

namespace App\Domain\HRM\Actions;

use App\Domain\HRM\Enums\LeaveRequestStatus;
use App\Domain\HRM\Exceptions\InvalidLeaveRequestTransitionException;
use App\Domain\HRM\Models\LeaveRequest;
use App\Domain\HRM\Support\LeaveDaysCalculator;
use Illuminate\Support\Facades\DB;

/**
 * Update non-status fields on a leave_request — ONLY while pending.
 *
 * Terminal states (approved, rejected) are read-only at this layer:
 * decided requests cannot have their content edited because the
 * approver's decision was made against specific facts. Editing the
 * dates/type/reason after approval would invalidate the approval
 * meaning. The Delete affordance still exists (for "created in error")
 * via .delete permission, but Edit does not.
 *
 * This Action throws InvalidLeaveRequestTransitionException if invoked
 * on a non-pending row. The exception self-renders as 422 with
 * error_code='invalid_transition' + from/to fields — no controller
 * try/catch needed.
 *
 * Status, approved_by, approved_at, approver_note are NOT in the
 * accepted fields — they only move through the dedicated Approve/
 * Reject Actions. The UpdateLeaveRequestRequest doesn't validate them
 * either; even if submitted they're silently ignored at the request
 * layer, and this Action's $data signature doesn't accept them.
 */
final class UpdateLeaveRequestAction
{
    public function __construct(private readonly LeaveDaysCalculator $calculator) {}

    /**
     * @param  array{
     *     employee_id?: int,
     *     leave_type?: string,
     *     start_date?: string,
     *     end_date?: string,
     *     day_part?: string,
     *     reason?: string|null,
     * }  $data
     */
    public function execute(LeaveRequest $request, array $data): LeaveRequest
    {
        if ($request->status !== LeaveRequestStatus::Pending) {
            // The "to" status is the same as "from" — the user wasn't
            // trying to transition, they were trying to edit. The
            // exception name "InvalidTransition" covers both "trying
            // to move state" and "trying to act on a state that
            // forbids action." Self-rendered as 422.
            throw new InvalidLeaveRequestTransitionException(
                from: $request->status,
                to: $request->status,
                message: sprintf(
                    'Cannot edit a leave request that is %s. Decided requests are read-only.',
                    $request->status->value,
                ),
            );
        }

        return DB::transaction(function () use ($request, $data): LeaveRequest {
            $request->fill($data);
            // Only recompute days_count when one of the inputs changed.
            // Editing reason alone shouldn't touch days_count — keeps the
            // audit diff focused on the actual user intent. Eloquent's
            // isDirty() against the freshly-filled but not-yet-saved
            // model is the natural check.
            if (
                $request->isDirty('start_date')
                || $request->isDirty('end_date')
                || $request->isDirty('day_part')
            ) {
                $request->days_count = $this->calculator->compute(
                    $request->start_date,
                    $request->end_date,
                    $request->day_part,
                );
            }
            $request->save();

            return $request->refresh();
        });
    }
}
