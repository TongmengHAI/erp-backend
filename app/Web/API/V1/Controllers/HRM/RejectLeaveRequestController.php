<?php

declare(strict_types=1);

namespace App\Web\API\V1\Controllers\HRM;

use App\Domain\HRM\Actions\RejectLeaveRequestAction;
use App\Domain\HRM\Models\LeaveRequest;
use App\Web\API\V1\Controllers\Concerns\AuthorizesHrmAccess;
use App\Web\API\V1\Controllers\Controller;
use App\Web\API\V1\Requests\HRM\RejectLeaveRequestRequest;
use App\Web\API\V1\Resources\HRM\LeaveRequestResource;
use Illuminate\Support\Facades\Auth;

/**
 * POST /api/v1/hrm/leave-requests/{leaveRequest}/reject.
 *
 * Mirror of ApproveLeaveRequestController — same permission
 * (.approve = decision-making authority), same single-Action delegation,
 * different terminal status.
 */
class RejectLeaveRequestController extends Controller
{
    use AuthorizesHrmAccess;

    public function __invoke(
        RejectLeaveRequestRequest $request,
        LeaveRequest $leaveRequest,
        RejectLeaveRequestAction $action,
    ): LeaveRequestResource {
        $this->authorizeHrm($request, 'hrm.leave_request.approve');

        /** @var array{note?: string|null} $data */
        $data = $request->validated();
        $approverId = (int) Auth::id();

        $rejected = $action->execute($leaveRequest, $data['note'] ?? null, $approverId);
        $rejected->load(['employee', 'approver']);

        return new LeaveRequestResource($rejected);
    }
}
