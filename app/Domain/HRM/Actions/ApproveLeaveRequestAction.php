<?php

declare(strict_types=1);

namespace App\Domain\HRM\Actions;

use App\Domain\HRM\Enums\LeaveRequestStatus;
use App\Domain\HRM\Exceptions\InvalidLeaveRequestTransitionException;
use App\Domain\HRM\Models\LeaveRequest;
use Illuminate\Support\Facades\DB;

/**
 * Transition a leave_request: pending → approved.
 *
 * Only callable from the pending state. Approving an already-approved row,
 * a rejected row, or any future status throws
 * InvalidLeaveRequestTransitionException (self-renders as 422,
 * error_code='invalid_transition', with from/to populated).
 *
 * approverId is passed explicitly rather than read from Auth::id() here —
 * the controller is the auth boundary. Keeping it explicit makes the
 * Action unit-testable without auth mocking, and makes it obvious that a
 * caller MUST have already established the approver's identity. The
 * controller layer reads Auth::id() and passes it in.
 *
 * Writes all three approval columns in lockstep so the composite DB CHECK
 * (status<>'pending' ⇒ approved_by AND approved_at NOT NULL) is always
 * satisfied. The note is optional; passing null is fine.
 */
final class ApproveLeaveRequestAction
{
    public function execute(LeaveRequest $request, ?string $note, int $approverId): LeaveRequest
    {
        if ($request->status !== LeaveRequestStatus::Pending) {
            throw new InvalidLeaveRequestTransitionException(
                from: $request->status,
                to: LeaveRequestStatus::Approved,
                message: sprintf(
                    'Cannot approve a leave request that is %s. Only pending requests can be approved.',
                    $request->status->value,
                ),
            );
        }

        return DB::transaction(function () use ($request, $note, $approverId): LeaveRequest {
            $request->status = LeaveRequestStatus::Approved;
            $request->approved_by = $approverId;
            $request->approved_at = now();
            $request->approver_note = $note;
            $request->save();

            return $request->refresh();
        });
    }
}
