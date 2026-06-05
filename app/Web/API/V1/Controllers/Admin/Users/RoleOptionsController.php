<?php

declare(strict_types=1);

namespace App\Web\API\V1\Controllers\Admin\Users;

use App\Web\API\V1\Controllers\Concerns\AuthorizesUserManagement;
use App\Web\API\V1\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;

/**
 * GET /api/v1/admin/users/role-options.
 *
 * Returns the global Spatie roles for the 'web' guard as a flat
 * list of {id, name} so the admin invite + edit forms can populate
 * their role Select.
 *
 * Phase 2A uses only the framework-seeded roles
 * (tenant_admin / accountant / viewer). Custom role creation lands
 * in Phase 2B; this endpoint will simply return more rows when that
 * ships.
 *
 * Auth: gated on users.view (same as the rest of /admin/users/*).
 * Non-admin → 404 per §10.6 feature-hide convention.
 */
final class RoleOptionsController extends Controller
{
    use AuthorizesUserManagement;

    public function __invoke(Request $request): JsonResponse
    {
        $this->authorizeUsersAccess($request);

        $roles = Role::query()
            ->where('guard_name', 'web')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(static fn (Role $r): array => [
                'id' => $r->id,
                'name' => $r->name,
            ])
            ->all();

        return response()->json(['data' => $roles]);
    }
}
