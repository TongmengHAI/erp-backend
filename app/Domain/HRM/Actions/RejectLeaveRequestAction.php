<?php

declare(strict_types=1);

namespace App\Domain\HRM\Actions;

use App\Domain\HRM\Enums\LeaveRequestStatus;
use App\Domain\HRM\Exceptions\InvalidLeaveRequestTransitionException;
use App\Domain\HRM\Models\LeaveRequest;
use Illuminate\Support\Facades\DB;

/**
 * Transition a leave_request: pending → rejected.
 *
 * Mirror of ApproveLeaveRequestAction — same guard, same column writes,
 * just a different terminal status. The "decision-making" act is the
 * same regardless of which way the manager decided, which is why the
 * .approve permission gates both: a manager has decision-making
 * authority, not "approval-only authority."
 *
 * The note is technically optional at this layer (DB allows null), but
 * the FormRequest at the HTTP boundary may decide to require it for
 * rejections specifically — that's a UX/policy call made at the request
 * layer, not enforced here.
 */
final class RejectLeaveRequestAction
{
    public function execute(LeaveRequest $request, ?string $note, int $approverId): LeaveRequest
    {
        if ($request->status !== LeaveRequestStatus::Pending) {
            throw new InvalidLeaveRequestTransitionException(
                from: $request->status,
                to: LeaveRequestStatus::Rejected,
                message: sprintf(
                    'Cannot reject a leave request that is %s. Only pending requests can be rejected.',
                    $request->status->value,
                ),
            );
        }

        return DB::transaction(function () use ($request, $note, $approverId): LeaveRequest {
            $request->status = LeaveRequestStatus::Rejected;
            $request->approved_by = $approverId;
            $request->approved_at = now();
            $request->approver_note = $note;
            $request->save();

            return $request->refresh();
        });
    }
}
