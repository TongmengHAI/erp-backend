<?php

declare(strict_types=1);

namespace App\Web\API\V1\Controllers\HRM;

use App\Domain\HRM\Actions\CreateEmployeeAction;
use App\Domain\HRM\Actions\UpdateEmployeeAction;
use App\Domain\HRM\Enums\EmployeeStatus;
use App\Domain\HRM\Models\Employee;
use App\Web\API\V1\Controllers\Concerns\AuthorizesHrmAccess;
use App\Web\API\V1\Controllers\Controller;
use App\Web\API\V1\Requests\HRM\StoreEmployeeRequest;
use App\Web\API\V1\Requests\HRM\UpdateEmployeeRequest;
use App\Web\API\V1\Resources\HRM\EmployeeBriefResource;
use App\Web\API\V1\Resources\HRM\EmployeeResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Employee CRUD endpoints — read inline, write through Actions.
 *
 * Tenant + company isolation is enforced by the global scopes
 * (TenantScope + CompanyScope) on the Employee model; cross-context
 * access manifests as a 404 from route-model binding (the record is
 * literally invisible to the user's query) rather than a 403.
 *
 * Permission gates are applied as the first line of every method via
 * AuthorizesHrmAccess — see that trait for the chokepoint policy.
 */
class EmployeeController extends Controller
{
    use AuthorizesHrmAccess;

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorizeHrm($request, 'hrm.employee.view');

        $validated = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'nullable', 'string',
                Rule::in(array_column(EmployeeStatus::cases(), 'value'))],
            // Per-department / per-position filters. Cross-tenant ids return
            // empty results (the Employee scope simply won't match) rather
            // than a 422 — that's correct: the param is just a where clause,
            // not a resource lookup. The load-bearing isolation lives in the
            // form-request side of each FK.
            'department_id' => ['sometimes', 'nullable', 'integer'],
            'position_id' => ['sometimes', 'nullable', 'integer'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        // Eager-load department + position so EmployeeBriefResource's
        // `department_name` + `position_title` accessors are single
        // attribute reads, not per-row queries (N+1 protection on a
        // paginated list endpoint).
        $query = Employee::query()->with(['department', 'position'])->orderBy('full_name');

        if (! empty($validated['search'])) {
            $needle = '%'.$validated['search'].'%';
            $query->where(function ($q) use ($needle): void {
                $q->where('full_name', 'ilike', $needle)
                    ->orWhere('employee_code', 'ilike', $needle);
            });
        }

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        // array_key_exists (not !empty) — the contract is: param present and
        // numeric ⇒ filter on it; param absent ⇒ no filter. We deliberately
        // do NOT accept `?department_id=` (empty string) as "employees with
        // no department" — that would be a separate `?has_department=false`
        // semantic for a future slice if needed.
        if (array_key_exists('department_id', $validated)
            && $validated['department_id'] !== null) {
            $query->where('department_id', $validated['department_id']);
        }

        // Per-position filter — mirror of department_id above. The
        // frontend's filter-chip pattern (Session 3 generalisation)
        // surfaces this via ?position_id=N on the Employee list URL.
        if (array_key_exists('position_id', $validated)
            && $validated['position_id'] !== null) {
            $query->where('position_id', $validated['position_id']);
        }

        $perPage = (int) ($validated['per_page'] ?? 25);

        return EmployeeBriefResource::collection($query->paginate($perPage));
    }

    public function show(Request $request, Employee $employee): EmployeeResource
    {
        $this->authorizeHrm($request, 'hrm.employee.view');

        // Route-model binding already filtered by tenant + company (global
        // scopes apply to the implicit query). If $employee resolved, the
        // user has structural access; we still gate the permission above.
        // Eager-load department + position for the resource projection —
        // two single reads rather than two relation triggers inside toArray.
        $employee->load(['department', 'position']);

        return new EmployeeResource($employee);
    }

    public function store(StoreEmployeeRequest $request, CreateEmployeeAction $action): JsonResponse
    {
        $this->authorizeHrm($request, 'hrm.employee.create');

        /** @var array{employee_code: string, full_name: string, email?: string|null, department_id?: int|null, position_id?: int|null, hire_date: string, status: string} $data */
        $data = $request->validated();
        $employee = $action->execute($data);
        // Load department + position for the response so the SPA can show
        // them on the detail page it redirects to. The action refresh()
        // reloaded the attributes; this loads the relations.
        $employee->load(['department', 'position']);

        return (new EmployeeResource($employee))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdateEmployeeRequest $request, Employee $employee, UpdateEmployeeAction $action): EmployeeResource
    {
        $this->authorizeHrm($request, 'hrm.employee.update');

        /** @var array{employee_code?: string, full_name?: string, email?: string|null, department_id?: int|null, position_id?: int|null, hire_date?: string, status?: string} $data */
        $data = $request->validated();
        $employee = $action->execute($employee, $data);
        $employee->load(['department', 'position']);

        return new EmployeeResource($employee);
    }

    public function destroy(Request $request, Employee $employee): JsonResponse
    {
        $this->authorizeHrm($request, 'hrm.employee.delete');

        $employee->delete();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
