<?php

declare(strict_types=1);

namespace App\Web\API\V1\Controllers\HRM;

use App\Domain\HRM\Actions\CreateAttendanceAction;
use App\Domain\HRM\Actions\UpdateAttendanceAction;
use App\Domain\HRM\Enums\AttendanceStatus;
use App\Domain\HRM\Models\AttendanceRecord;
use App\Web\API\V1\Controllers\Concerns\AuthorizesHrmAccess;
use App\Web\API\V1\Controllers\Controller;
use App\Web\API\V1\Requests\HRM\StoreAttendanceRequest;
use App\Web\API\V1\Requests\HRM\UpdateAttendanceRequest;
use App\Web\API\V1\Resources\HRM\AttendanceRecordBriefResource;
use App\Web\API\V1\Resources\HRM\AttendanceRecordResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * AttendanceRecord CRUD endpoints — read inline, write through Actions.
 *
 * Same chokepoint pattern as Employee / Department / LeaveRequest
 * controllers: tenant + company isolation via global scopes (404 on
 * cross-context access), permission gates via AuthorizesHrmAccess.
 *
 * The (employee, date) uniqueness conflict surfaces as a 422 with
 * the named-fields message via the StoreRequest's after() closure —
 * the controller doesn't need any special handling for it.
 *
 * Default index sort is `date DESC` — the manager's "what happened
 * recently" view is the dominant access pattern.
 */
class AttendanceController extends Controller
{
    use AuthorizesHrmAccess;

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorizeHrm($request, 'hrm.attendance.view');

        $validated = $request->validate([
            'employee_id' => ['sometimes', 'nullable', 'integer'],
            'status' => [
                'sometimes', 'nullable', 'string',
                Rule::in(array_column(AttendanceStatus::cases(), 'value')),
            ],
            // Date window — list "attendance within this range". Both
            // optional and independent; same semantics as leave_requests
            // from/to filters but inverted (here we filter the row's
            // single `date` column, not a span).
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        // Eager-load employee so the brief resource doesn't trigger
        // per-row queries.
        $query = AttendanceRecord::query()
            ->with('employee')
            ->orderByDesc('date')
            ->orderByDesc('id'); // tiebreaker so paginate() is deterministic

        if (array_key_exists('employee_id', $validated) && $validated['employee_id'] !== null) {
            $query->where('employee_id', $validated['employee_id']);
        }
        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        if (! empty($validated['from'])) {
            $query->whereDate('date', '>=', $validated['from']);
        }
        if (! empty($validated['to'])) {
            $query->whereDate('date', '<=', $validated['to']);
        }

        $perPage = (int) ($validated['per_page'] ?? 25);

        return AttendanceRecordBriefResource::collection($query->paginate($perPage));
    }

    public function show(Request $request, AttendanceRecord $attendance): AttendanceRecordResource
    {
        $this->authorizeHrm($request, 'hrm.attendance.view');

        $attendance->load('employee');

        return new AttendanceRecordResource($attendance);
    }

    public function store(StoreAttendanceRequest $request, CreateAttendanceAction $action): JsonResponse
    {
        $this->authorizeHrm($request, 'hrm.attendance.create');

        /** @var array{employee_id: int, date: string, clock_in?: string|null, clock_out?: string|null, status: string, notes?: string|null} $data */
        $data = $request->validated();
        $record = $action->execute($data);
        $record->load('employee');

        return (new AttendanceRecordResource($record))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdateAttendanceRequest $request, AttendanceRecord $attendance, UpdateAttendanceAction $action): AttendanceRecordResource
    {
        $this->authorizeHrm($request, 'hrm.attendance.update');

        /** @var array{employee_id?: int, date?: string, clock_in?: string|null, clock_out?: string|null, status?: string, notes?: string|null} $data */
        $data = $request->validated();
        $record = $action->execute($attendance, $data);
        $record->load('employee');

        return new AttendanceRecordResource($record);
    }

    public function destroy(Request $request, AttendanceRecord $attendance): JsonResponse
    {
        $this->authorizeHrm($request, 'hrm.attendance.delete');

        $attendance->delete();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
