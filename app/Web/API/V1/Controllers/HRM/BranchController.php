<?php

declare(strict_types=1);

namespace App\Web\API\V1\Controllers\HRM;

use App\Domain\HRM\Actions\CreateBranchAction;
use App\Domain\HRM\Actions\UpdateBranchAction;
use App\Domain\HRM\Enums\BranchStatus;
use App\Domain\HRM\Models\Branch;
use App\Web\API\V1\Controllers\Concerns\AuthorizesHrmAccess;
use App\Web\API\V1\Controllers\Controller;
use App\Web\API\V1\Requests\HRM\StoreBranchRequest;
use App\Web\API\V1\Requests\HRM\UpdateBranchRequest;
use App\Web\API\V1\Resources\HRM\BranchBriefResource;
use App\Web\API\V1\Resources\HRM\BranchResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Branch CRUD endpoints — mirror of PositionController.
 *
 * Same chokepoint pattern (tenant + company isolation via global scopes
 * → 404 on cross-context access; permission gates via AuthorizesHrmAccess).
 *
 * Index supports search across name OR code OR city (ILIKE), plus the
 * standard status filter. Default sort: name ASC.
 */
class BranchController extends Controller
{
    use AuthorizesHrmAccess;

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorizeHrm($request, 'hrm.branch.view');

        $validated = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'nullable', 'string',
                Rule::in(array_column(BranchStatus::cases(), 'value'))],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Branch::query()->orderBy('name');

        if (! empty($validated['search'])) {
            $needle = '%'.$validated['search'].'%';
            $query->where(function ($q) use ($needle): void {
                $q->where('name', 'ilike', $needle)
                    ->orWhere('code', 'ilike', $needle)
                    ->orWhere('city', 'ilike', $needle);
            });
        }

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $perPage = (int) ($validated['per_page'] ?? 25);

        return BranchBriefResource::collection($query->paginate($perPage));
    }

    public function show(Request $request, Branch $branch): BranchResource
    {
        $this->authorizeHrm($request, 'hrm.branch.view');

        $branch->loadCount('employees');

        return new BranchResource($branch);
    }

    public function store(StoreBranchRequest $request, CreateBranchAction $action): JsonResponse
    {
        $this->authorizeHrm($request, 'hrm.branch.create');

        /** @var array{code: string, name: string, description?: string|null, address?: string|null, city?: string|null, country_code?: string|null, phone?: string|null, status: string} $data */
        $data = $request->validated();
        $branch = $action->execute($data);

        return (new BranchResource($branch))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdateBranchRequest $request, Branch $branch, UpdateBranchAction $action): BranchResource
    {
        $this->authorizeHrm($request, 'hrm.branch.update');

        /** @var array{code?: string, name?: string, description?: string|null, address?: string|null, city?: string|null, country_code?: string|null, phone?: string|null, status?: string} $data */
        $data = $request->validated();
        $branch = $action->execute($branch, $data);

        return new BranchResource($branch);
    }

    public function destroy(Request $request, Branch $branch): JsonResponse
    {
        $this->authorizeHrm($request, 'hrm.branch.delete');

        $branch->delete();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
