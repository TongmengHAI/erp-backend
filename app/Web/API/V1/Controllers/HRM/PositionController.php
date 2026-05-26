<?php

declare(strict_types=1);

namespace App\Web\API\V1\Controllers\HRM;

use App\Domain\HRM\Actions\CreatePositionAction;
use App\Domain\HRM\Actions\UpdatePositionAction;
use App\Domain\HRM\Enums\PositionStatus;
use App\Domain\HRM\Models\Position;
use App\Web\API\V1\Controllers\Concerns\AuthorizesHrmAccess;
use App\Web\API\V1\Controllers\Controller;
use App\Web\API\V1\Requests\HRM\StorePositionRequest;
use App\Web\API\V1\Requests\HRM\UpdatePositionRequest;
use App\Web\API\V1\Resources\HRM\PositionBriefResource;
use App\Web\API\V1\Resources\HRM\PositionResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Position CRUD endpoints — read inline, write through Actions.
 *
 * Same chokepoint pattern as Employee / Department / LeaveRequest /
 * Attendance controllers: tenant + company isolation via global scopes
 * (404 on cross-context access), permission gates via AuthorizesHrmAccess.
 */
class PositionController extends Controller
{
    use AuthorizesHrmAccess;

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorizeHrm($request, 'hrm.position.view');

        $validated = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'nullable', 'string',
                Rule::in(array_column(PositionStatus::cases(), 'value'))],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        // Default sort: title ASC — the most natural ordering for a
        // role list (alphabetical scan).
        $query = Position::query()->orderBy('title');

        if (! empty($validated['search'])) {
            $needle = '%'.$validated['search'].'%';
            $query->where(function ($q) use ($needle): void {
                $q->where('title', 'ilike', $needle)
                    ->orWhere('code', 'ilike', $needle);
            });
        }

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $perPage = (int) ($validated['per_page'] ?? 25);

        return PositionBriefResource::collection($query->paginate($perPage));
    }

    public function show(Request $request, Position $position): PositionResource
    {
        $this->authorizeHrm($request, 'hrm.position.view');

        // loadCount pre-populates `employees_count` — mirror of
        // DepartmentController::show.
        $position->loadCount('employees');

        return new PositionResource($position);
    }

    public function store(StorePositionRequest $request, CreatePositionAction $action): JsonResponse
    {
        $this->authorizeHrm($request, 'hrm.position.create');

        /** @var array{code: string, title: string, description?: string|null, status: string} $data */
        $data = $request->validated();
        $position = $action->execute($data);

        return (new PositionResource($position))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdatePositionRequest $request, Position $position, UpdatePositionAction $action): PositionResource
    {
        $this->authorizeHrm($request, 'hrm.position.update');

        /** @var array{code?: string, title?: string, description?: string|null, status?: string} $data */
        $data = $request->validated();
        $position = $action->execute($position, $data);

        return new PositionResource($position);
    }

    public function destroy(Request $request, Position $position): JsonResponse
    {
        $this->authorizeHrm($request, 'hrm.position.delete');

        $position->delete();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
