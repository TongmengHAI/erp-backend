<?php

declare(strict_types=1);

namespace App\Web\API\V1\Controllers\Admin\Users;

use App\Models\User;
use App\Web\API\V1\Controllers\Concerns\AuthorizesUserManagement;
use App\Web\API\V1\Controllers\Controller;
use App\Web\API\V1\Requests\Admin\Users\IndexUsersRequest;
use App\Web\API\V1\Requests\Admin\Users\UpdateUserRequest;
use App\Web\API\V1\Resources\Admin\AdminUserBriefResource;
use App\Web\API\V1\Resources\Admin\AdminUserResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * Resource controller for the /admin/users surface.
 *
 *   GET    /admin/users         index   — paginated list (status filter,
 *                                          search, role filter)
 *   GET    /admin/users/{user}  show    — full payload incl. role snapshot
 *   PATCH  /admin/users/{user}  update  — name + role only; status
 *                                          transitions live on dedicated
 *                                          invokable controllers
 *
 * Auth model:
 *   • authorizeUsersAccess() at the top of every method — 404 if !users.view.
 *     Non-admin users (no users.* perms in Phase 2A) see /admin/users/*
 *     as "feature does not exist" per CLAUDE.md §10.6's 404-not-403
 *     convention (mirrors SuperAdminGuard).
 *   • authorizeUsersAction() for write methods (update / transitions).
 *   • authorizeUserTargetIsInCurrentTenant() on every {user} resolution —
 *     User is NOT tenant-scoped via global scope, so cross-tenant checks
 *     are explicit. SA targets (tenant_id=NULL) and other-tenant targets
 *     both → 404.
 */
final class UserController extends Controller
{
    use AuthorizesUserManagement;

    public function index(IndexUsersRequest $request): AnonymousResourceCollection
    {
        $this->authorizeUsersAccess($request);

        $actor = $request->user();
        $tenantId = $actor?->tenant_id;

        /** @var array{status?: string, include_deactivated?: bool, search?: string, role_id?: int, per_page?: int} $filters */
        $filters = $request->validated();
        $includeDeactivated = (bool) ($filters['include_deactivated'] ?? false);

        $query = User::query()
            ->when($includeDeactivated, fn (Builder $q) => $q->withTrashed())
            ->where('tenant_id', $tenantId)
            ->with('roles');

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['search']) && $filters['search'] !== '') {
            $search = '%'.$filters['search'].'%';
            $query->where(function (Builder $q) use ($search): void {
                $q->where('name', 'ilike', $search)
                    ->orWhere('email', 'ilike', $search);
            });
        }

        if (isset($filters['role_id'])) {
            $roleId = (int) $filters['role_id'];
            $query->whereHas(
                'roles',
                fn (Builder $r) => $r->whereKey($roleId)
            );
        }

        $perPage = $filters['per_page'] ?? 15;
        $page = $query->orderBy('name')->paginate($perPage);

        return AdminUserBriefResource::collection($page);
    }

    public function show(Request $request, int $userId): AdminUserResource
    {
        $this->authorizeUsersAccess($request);

        // Manual fetch with withTrashed so deactivated users are visible
        // to admins; cross-tenant gate via authorizeUserTargetIsInCurrentTenant.
        $target = User::withTrashed()->with('roles')->findOrFail($userId);
        $this->authorizeUserTargetIsInCurrentTenant($request, $target);

        return new AdminUserResource($target);
    }

    public function update(UpdateUserRequest $request, int $userId): AdminUserResource
    {
        $this->authorizeUsersAccess($request);
        $this->authorizeUsersAction($request, 'users.update');

        $target = User::withTrashed()->with('roles')->findOrFail($userId);
        $this->authorizeUserTargetIsInCurrentTenant($request, $target);

        /** @var array{name?: string, role_id?: int} $data */
        $data = $request->validated();

        DB::transaction(function () use ($target, $data, $request): void {
            if (isset($data['name'])) {
                $target->name = $data['name'];
                $target->save();
            }

            if (isset($data['role_id'])) {
                $role = Role::findById($data['role_id'], 'web');
                $actor = $request->user();
                // Single role per user in Phase 2A — revoke whatever's
                // there, assign the new one. Both helpers route through
                // HasTenantRoles' team-scoped wrapper so Spatie's
                // team_id stays correct.
                if ($actor !== null && $actor->tenant !== null) {
                    $tenant = $actor->tenant;
                    /** @var Role $existing */
                    foreach ($target->roles as $existing) {
                        $target->revokeTenantRole($tenant, $existing->name);
                    }
                    $target->assignTenantRole($tenant, $role->name);
                }
            }
        });

        return new AdminUserResource($target->fresh(['roles']) ?? $target);
    }
}
