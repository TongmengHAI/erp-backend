<?php

declare(strict_types=1);

namespace App\Web\API\V1\Controllers\HRM;

use App\Domain\HRM\Actions\CreateLeaveRequestAction;
use App\Domain\HRM\Actions\UpdateLeaveRequestAction;
use App\Domain\HRM\Enums\LeaveRequestStatus;
use App\Domain\HRM\Enums\LeaveType;
use App\Domain\HRM\Models\LeaveRequest;
use App\Web\API\V1\Controllers\Concerns\AuthorizesHrmAccess;
use App\Web\API\V1\Controllers\Controller;
use App\Web\API\V1\Requests\HRM\StoreLeaveRequestRequest;
use App\Web\API\V1\Requests\HRM\UpdateLeaveRequestRequest;
use App\Web\API\V1\Resources\HRM\LeaveRequestBriefResource;
use App\Web\API\V1\Resources\HRM\LeaveRequestResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * LeaveRequest CRUD endpoints — read inline, write through Actions.
 *
 * Same chokepoint pattern as Employee/Department controllers: tenant +
 * company isolation via global scopes (404 on cross-context access),
 * permission gates via AuthorizesHrmAccess.
 *
 * The state-transition endpoints (approve, reject) live in separate
 * invokable controllers (ApproveLeaveRequestController,
 * RejectLeaveRequestController) — they have different permission
 * (hrm.leave_request.approve), different request shape, and a different
 * domain Action. Forcing them into methods on this class would conflate
 * three orthogonal responsibilities.
 *
 * The update endpoint delegates to UpdateLeaveRequestAction which throws
 * InvalidLeaveRequestTransitionException for non-pending rows; the
 * exception self-renders as 422 with error_code='invalid_transition'.
 * No controller try/catch — the exception's render() method handles it.
 */
class LeaveRequestController extends Controller
{
    use AuthorizesHrmAccess;

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorizeHrm($request, 'hrm.leave_request.view');

        $validated = $request->validate([
            'employee_id' => ['sometimes', 'nullable', 'integer'],
            'status' => [
                'sometimes', 'nullable', 'string',
                Rule::in(array_column(LeaveRequestStatus::cases(), 'value')),
            ],
            'leave_type' => [
                'sometimes', 'nullable', 'string',
                Rule::in(array_column(LeaveType::cases(), 'value')),
            ],
            // Date window — list "requests overlapping this window." Both
            // optional and independent: pass only `from` for "from this
            // date forward," only `to` for "up to this date."
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        // Eager-load employee + approver so the brief resource doesn't
        // trigger per-row queries. Default sort: most recently submitted
        // first (newest pending requests rise to the top of the list,
        // which is what a manager opening the inbox wants to see).
        $query = LeaveRequest::query()
            ->with(['employee', 'approver'])
            ->orderByDesc('created_at');

        if (array_key_exists('employee_id', $validated) && $validated['employee_id'] !== null) {
            $query->where('employee_id', $validated['employee_id']);
        }
        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        if (! empty($validated['leave_type'])) {
            $query->where('leave_type', $validated['leave_type']);
        }
        if (! empty($validated['from'])) {
            // Overlap semantic: request's end_date is on/after the window
            // start. Combined with the `to` clause below, this is the
            // standard interval-overlap test.
            $query->where('end_date', '>=', $validated['from']);
        }
        if (! empty($validated['to'])) {
            $query->where('start_date', '<=', $validated['to']);
        }

        $perPage = (int) ($validated['per_page'] ?? 25);

        return LeaveRequestBriefResource::collection($query->paginate($perPage));
    }

    public function show(Request $request, LeaveRequest $leaveRequest): LeaveRequestResource
    {
        $this->authorizeHrm($request, 'hrm.leave_request.view');

        $leaveRequest->load(['employee', 'approver']);

        return new LeaveRequestResource($leaveRequest);
    }

    public function store(StoreLeaveRequestRequest $request, CreateLeaveRequestAction $action): JsonResponse
    {
        $this->authorizeHrm($request, 'hrm.leave_request.create');

        /** @var array{employee_id: int, leave_type: string, start_date: string, end_date: string, reason?: string|null} $data */
        $data = $request->validated();
        $leaveRequest = $action->execute($data);
        $leaveRequest->load(['employee', 'approver']);

        return (new LeaveRequestResource($leaveRequest))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdateLeaveRequestRequest $request, LeaveRequest $leaveRequest, UpdateLeaveRequestAction $action): LeaveRequestResource
    {
        $this->authorizeHrm($request, 'hrm.leave_request.update');

        /** @var array{employee_id?: int, leave_type?: string, start_date?: string, end_date?: string, reason?: string|null} $data */
        $data = $request->validated();
        // The Action throws InvalidLeaveRequestTransitionException if the
        // row isn't pending. Its render() method self-renders 422 with
        // error_code='invalid_transition' + from/to. No try/catch here.
        $leaveRequest = $action->execute($leaveRequest, $data);
        $leaveRequest->load(['employee', 'approver']);

        return new LeaveRequestResource($leaveRequest);
    }

    public function destroy(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        $this->authorizeHrm($request, 'hrm.leave_request.delete');

        $leaveRequest->delete();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
