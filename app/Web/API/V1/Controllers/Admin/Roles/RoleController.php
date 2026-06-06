<?php

declare(strict_types=1);

namespace App\Web\API\V1\Controllers\Admin\Roles;

use App\Domain\Identity\Actions\CreateRoleAction;
use App\Domain\Identity\Actions\DeleteRoleAction;
use App\Domain\Identity\Actions\UpdateRoleAction;
use App\Domain\Identity\Models\Role;
use App\Web\API\V1\Controllers\Concerns\AuthorizesRoleManagement;
use App\Web\API\V1\Controllers\Controller;
use App\Web\API\V1\Requests\Admin\Roles\CreateRoleRequest;
use App\Web\API\V1\Requests\Admin\Roles\IndexRolesRequest;
use App\Web\API\V1\Requests\Admin\Roles\UpdateRoleRequest;
use App\Web\API\V1\Resources\Admin\AdminRoleBriefResource;
use App\Web\API\V1\Resources\Admin\AdminRoleResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Resource controller for /api/v1/admin/roles.
 *
 *   GET    /admin/roles          index   — paginated list (system +
 *                                          tenant's custom rows)
 *   POST   /admin/roles          store   — create a custom role
 *   GET    /admin/roles/{role}   show    — full payload with permissions
 *   PATCH  /admin/roles/{role}   update  — partial update (name /
 *                                          description / permissions)
 *   DELETE /admin/roles/{role}   destroy — soft-delete (blocked if
 *                                          users assigned)
 *
 * Auth model mirrors UserController (CLAUDE.md §10.6 feature-hide
 * convention):
 *   • authorizeRolesAccess() — 404 if !roles.view.
 *   • authorizeRolesAction() — 403 if missing the specific action perm.
 *   • authorizeRoleTargetIsInCurrentTenant() — 404 if the role's
 *     custom-scope tenant differs from the actor's.
 *
 * System role mutation is rejected at the ACTION layer via
 * RoleImmutableException (self-renders 403). Defense-in-depth — both
 * the Update and Delete Actions enforce it; the FormRequest can't
 * (it doesn't load the Role row).
 */
final class RoleController extends Controller
{
    use AuthorizesRoleManagement;

    public function index(IndexRolesRequest $request): AnonymousResourceCollection
    {
        $this->authorizeRolesAccess($request);

        $actor = $request->user();
        $tenantId = $actor?->tenant_id;
        /** @var array{kind?: string, search?: string, per_page?: int} $filters */
        $filters = $request->validated();

        $query = Role::query()
            ->withCount('users')
            ->when(
                ($filters['kind'] ?? null) === 'system',
                fn (Builder $q) => $q->where('is_system', true),
                fn (Builder $q) => $tenantId === null
                    ? $q->whereRaw('1 = 0')
                    : $q->where(function (Builder $inner) use ($tenantId): void {
                        $inner->where('is_system', true)
                            ->orWhere('team_id', $tenantId);
                    })
            )
            ->when(
                ($filters['kind'] ?? null) === 'custom',
                fn (Builder $q) => $q->where('is_system', false)
            );

        if (isset($filters['search']) && $filters['search'] !== '') {
            $search = '%'.$filters['search'].'%';
            $query->where(function (Builder $q) use ($search): void {
                $q->where('name', 'ilike', $search)
                    ->orWhere('description', 'ilike', $search);
            });
        }

        $perPage = $filters['per_page'] ?? 25;
        $page = $query
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->paginate($perPage);

        return AdminRoleBriefResource::collection($page);
    }

    public function show(Request $request, int $roleId): AdminRoleResource
    {
        $this->authorizeRolesAccess($request);

        $role = Role::withTrashed()
            ->with('permissions')
            ->withCount('users')
            ->findOrFail($roleId);
        $this->authorizeRoleTargetIsInCurrentTenant($request, $role);

        return new AdminRoleResource($role);
    }

    public function store(CreateRoleRequest $request, CreateRoleAction $action): JsonResponse
    {
        $this->authorizeRolesAccess($request);
        $this->authorizeRolesAction($request, 'roles.create');

        $actor = $request->user();
        $tenantId = $actor?->tenant_id;
        if ($tenantId === null) {
            // Tenant-context absence on this route is unreachable (the
            // 'tenant' middleware would have rejected); the guard exists
            // so static analysis can prove tenantId is int by the time
            // it reaches CreateRoleAction.
            abort(403);
        }

        /** @var array{name: string, description?: ?string, permission_ids: list<int>} $data */
        $data = $request->validated();

        $role = $action->execute(
            tenantId: $tenantId,
            name: $data['name'],
            description: $data['description'] ?? null,
            permissionIds: $data['permission_ids'],
        );
        $role->loadCount('users');

        return (new AdminRoleResource($role))->response()->setStatusCode(201);
    }

    public function update(UpdateRoleRequest $request, int $roleId, UpdateRoleAction $action): AdminRoleResource
    {
        $this->authorizeRolesAccess($request);
        $this->authorizeRolesAction($request, 'roles.update');

        $role = Role::with('permissions')->findOrFail($roleId);
        $this->authorizeRoleTargetIsInCurrentTenant($request, $role);

        /** @var array{name?: string, description?: ?string, permission_ids?: list<int>} $data */
        $data = $request->validated();

        $updated = $action->execute(
            role: $role,
            name: $data['name'] ?? null,
            description: array_key_exists('description', $data) ? $data['description'] : null,
            permissionIds: $data['permission_ids'] ?? null,
        );
        $updated->loadCount('users');

        return new AdminRoleResource($updated);
    }

    public function destroy(Request $request, int $roleId, DeleteRoleAction $action): JsonResponse
    {
        $this->authorizeRolesAccess($request);
        $this->authorizeRolesAction($request, 'roles.delete');

        $role = Role::findOrFail($roleId);
        $this->authorizeRoleTargetIsInCurrentTenant($request, $role);

        $action->execute($role);

        return new JsonResponse(null, 204);
    }
}
