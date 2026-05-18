<?php

declare(strict_types=1);

namespace App\Web\API\V1\Controllers\HRM;

use App\Domain\HRM\Actions\CreateDepartmentAction;
use App\Domain\HRM\Actions\UpdateDepartmentAction;
use App\Domain\HRM\Enums\DepartmentStatus;
use App\Domain\HRM\Models\Department;
use App\Web\API\V1\Controllers\Concerns\AuthorizesHrmAccess;
use App\Web\API\V1\Controllers\Controller;
use App\Web\API\V1\Requests\HRM\StoreDepartmentRequest;
use App\Web\API\V1\Requests\HRM\UpdateDepartmentRequest;
use App\Web\API\V1\Resources\HRM\DepartmentBriefResource;
use App\Web\API\V1\Resources\HRM\DepartmentResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Department CRUD endpoints — read inline, write through Actions.
 *
 * Same architectural pattern as EmployeeController: tenant + company
 * isolation enforced by global scopes (TenantScope + CompanyScope) on
 * the Department model; cross-context access manifests as 404 from
 * route-model binding. Permission gates applied as the first line of
 * every method via AuthorizesHrmAccess.
 */
class DepartmentController extends Controller
{
    use AuthorizesHrmAccess;

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorizeHrm($request, 'hrm.department.view');

        $validated = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'nullable', 'string',
                Rule::in(array_column(DepartmentStatus::cases(), 'value'))],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Department::query()->orderBy('name');

        if (! empty($validated['search'])) {
            $needle = '%'.$validated['search'].'%';
            $query->where(function ($q) use ($needle): void {
                $q->where('name', 'ilike', $needle)
                    ->orWhere('code', 'ilike', $needle);
            });
        }

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $perPage = (int) ($validated['per_page'] ?? 25);

        return DepartmentBriefResource::collection($query->paginate($perPage));
    }

    public function show(Request $request, Department $department): DepartmentResource
    {
        $this->authorizeHrm($request, 'hrm.department.view');

        // Route-model binding already filtered by tenant + company (global
        // scopes apply to the implicit query). If $department resolved, the
        // user has structural access; we still gate the permission above.
        return new DepartmentResource($department);
    }

    public function store(StoreDepartmentRequest $request, CreateDepartmentAction $action): JsonResponse
    {
        $this->authorizeHrm($request, 'hrm.department.create');

        /** @var array{code: string, name: string, description?: string|null, status: string} $data */
        $data = $request->validated();
        $department = $action->execute($data);

        return (new DepartmentResource($department))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdateDepartmentRequest $request, Department $department, UpdateDepartmentAction $action): DepartmentResource
    {
        $this->authorizeHrm($request, 'hrm.department.update');

        /** @var array{code?: string, name?: string, description?: string|null, status?: string} $data */
        $data = $request->validated();
        $department = $action->execute($department, $data);

        return new DepartmentResource($department);
    }

    public function destroy(Request $request, Department $department): JsonResponse
    {
        $this->authorizeHrm($request, 'hrm.department.delete');

        $department->delete();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
