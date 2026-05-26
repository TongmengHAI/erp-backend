<?php

declare(strict_types=1);

namespace App\Web\API\V1\Controllers\HRM;

use App\Domain\HRM\Actions\CreateLeaveBalanceAction;
use App\Domain\HRM\Actions\UpdateLeaveBalanceAction;
use App\Domain\HRM\Enums\LeaveType;
use App\Domain\HRM\Models\LeaveBalance;
use App\Domain\HRM\Services\LeaveBalanceQueryService;
use App\Web\API\V1\Controllers\Concerns\AuthorizesHrmAccess;
use App\Web\API\V1\Controllers\Controller;
use App\Web\API\V1\Requests\HRM\StoreLeaveBalanceRequest;
use App\Web\API\V1\Requests\HRM\UpdateLeaveBalanceRequest;
use App\Web\API\V1\Resources\HRM\LeaveBalanceBriefResource;
use App\Web\API\V1\Resources\HRM\LeaveBalanceResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Leave Balance CRUD endpoints.
 *
 * The compute-time aggregate (consumed_days, remaining_days) lives in
 * LeaveBalanceQueryService. Both index AND show route through the
 * service so the wire shape is uniform.
 *
 * Index filters: employee_id, leave_type (allocated subset), period_year.
 * Default sort: period_year DESC, then employee name ASC (most-recent
 * year first; alphabetical within a year). The order-by-employee-name
 * needs the joined employees table — added via leftJoin so soft-deleted
 * employees still surface their historical balance rows.
 */
class LeaveBalanceController extends Controller
{
    use AuthorizesHrmAccess;

    public function __construct(private readonly LeaveBalanceQueryService $balances) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorizeHrm($request, 'hrm.leave_balance.view');

        $validated = $request->validate([
            'employee_id' => ['sometimes', 'integer', 'min:1'],
            'leave_type' => ['sometimes', 'nullable', 'string',
                Rule::in([LeaveType::Annual->value, LeaveType::Sick->value])],
            'period_year' => ['sometimes', 'integer', 'min:2000', 'max:2100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = $this->balances->query()
            ->with('employee')
            ->leftJoin('employees', 'employees.id', '=', 'leave_balances.employee_id')
            ->orderBy('leave_balances.period_year', 'desc')
            ->orderBy('employees.full_name')
            ->select('leave_balances.*')
            ->selectRaw('COALESCE(consumed.total_days, 0)::numeric(5,1) AS consumed_days')
            ->selectRaw('(leave_balances.allocated_days - COALESCE(consumed.total_days, 0))::numeric(5,1) AS remaining_days');

        if (! empty($validated['employee_id'])) {
            $query->where('leave_balances.employee_id', (int) $validated['employee_id']);
        }
        if (! empty($validated['leave_type'])) {
            $query->where('leave_balances.leave_type', $validated['leave_type']);
        }
        if (! empty($validated['period_year'])) {
            $query->where('leave_balances.period_year', (int) $validated['period_year']);
        }

        $perPage = (int) ($validated['per_page'] ?? 25);

        return LeaveBalanceBriefResource::collection($query->paginate($perPage));
    }

    public function show(Request $request, LeaveBalance $leaveBalance): LeaveBalanceResource
    {
        $this->authorizeHrm($request, 'hrm.leave_balance.view');

        // Re-fetch through the service so consumed_days / remaining_days
        // ride with the row. Route-binding gave us the bare model;
        // routing through the JOIN'd query attaches the computed columns.
        $row = $this->balances->query()
            ->with('employee')
            ->where('leave_balances.id', $leaveBalance->id)
            ->firstOrFail();

        return new LeaveBalanceResource($row);
    }

    public function store(StoreLeaveBalanceRequest $request, CreateLeaveBalanceAction $action): JsonResponse
    {
        $this->authorizeHrm($request, 'hrm.leave_balance.create');

        /** @var array{employee_id: int, leave_type: string, period_year: int, allocated_days: float|string, notes?: string|null} $data */
        $data = $request->validated();
        $balance = $action->execute($data);

        // Route the just-created row back through the service for the
        // response so consumed_days / remaining_days surface on the
        // 201 body (consumed is 0 for a fresh balance, remaining ==
        // allocated — but the wire shape is still correct + uniform).
        $row = $this->balances->query()
            ->with('employee')
            ->where('leave_balances.id', $balance->id)
            ->firstOrFail();

        return (new LeaveBalanceResource($row))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(
        UpdateLeaveBalanceRequest $request,
        LeaveBalance $leaveBalance,
        UpdateLeaveBalanceAction $action,
    ): LeaveBalanceResource {
        $this->authorizeHrm($request, 'hrm.leave_balance.update');

        /** @var array{allocated_days?: float|string, notes?: string|null} $data */
        $data = $request->validated();
        $action->execute($leaveBalance, $data);

        $row = $this->balances->query()
            ->with('employee')
            ->where('leave_balances.id', $leaveBalance->id)
            ->firstOrFail();

        return new LeaveBalanceResource($row);
    }

    public function destroy(Request $request, LeaveBalance $leaveBalance): JsonResponse
    {
        $this->authorizeHrm($request, 'hrm.leave_balance.delete');

        $leaveBalance->delete();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
