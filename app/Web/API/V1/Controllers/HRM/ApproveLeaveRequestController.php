<?php

declare(strict_types=1);

namespace App\Web\API\V1\Controllers\HRM;

use App\Domain\HRM\Actions\ApproveLeaveRequestAction;
use App\Domain\HRM\Models\LeaveRequest;
use App\Web\API\V1\Controllers\Concerns\AuthorizesHrmAccess;
use App\Web\API\V1\Controllers\Controller;
use App\Web\API\V1\Requests\HRM\ApproveLeaveRequestRequest;
use App\Web\API\V1\Resources\HRM\LeaveRequestResource;
use Illuminate\Support\Facades\Auth;

/**
 * POST /api/v1/hrm/leave-requests/{leaveRequest}/approve.
 *
 * Invokable controller — one verb, one action, no shared state with the
 * resource controller. The .approve permission represents
 * decision-making authority (gates both approve and reject), separate
 * from .update which is for editing pending requests.
 *
 * The Action throws InvalidLeaveRequestTransitionException when the row
 * isn't pending; the exception's render() method handles the 422
 * response. No try/catch in this controller.
 */
class ApproveLeaveRequestController extends Controller
{
    use AuthorizesHrmAccess;

    public function __invoke(
        ApproveLeaveRequestRequest $request,
        LeaveRequest $leaveRequest,
        ApproveLeaveRequestAction $action,
    ): LeaveRequestResource {
        $this->authorizeHrm($request, 'hrm.leave_request.approve');

        /** @var array{note?: string|null} $data */
        $data = $request->validated();
        $approverId = (int) Auth::id();

        $approved = $action->execute($leaveRequest, $data['note'] ?? null, $approverId);
        $approved->load(['employee', 'approver']);

        return new LeaveRequestResource($approved);
    }
}
