<?php

declare(strict_types=1);

namespace App\Web\API\V1\Controllers\Admin\Users;

use App\Domain\Identity\Models\Role;
use App\Web\API\V1\Controllers\Concerns\AuthorizesUserManagement;
use App\Web\API\V1\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/admin/users/role-options.
 *
 * Returns the role-options list that populates the role Select in the
 * admin invite + edit forms. Each row: {id, name, label} so the SPA
 * renders the i18n label for system rows + the admin-entered name for
 * custom rows.
 *
 * Phase 2B FILTERING (Tightening 2):
 *
 *   - Actor has roles.assign → returns SYSTEM roles + the tenant's
 *     CUSTOM roles (joined via Role::forTenant scope).
 *
 *   - Actor lacks roles.assign → returns SYSTEM roles only. The
 *     actor's users.invite permission implicitly covers system-role
 *     assignment (Phase 2A behavior); custom-role assignment requires
 *     roles.assign per the locked Q11 granularity split.
 *
 * The endpoint's response shape stays the same in both cases — only
 * the row count differs. The frontend reads auth.can('roles.assign')
 * for its own display copy ("Showing system roles only") and
 * cross-references this endpoint's response.
 *
 * Auth: gated on users.view (same as the rest of /admin/users/*).
 * Non-admin → 404 per §10.6 feature-hide convention.
 *
 * Order: system rows first (by name), then custom rows (by name) — so
 * the dropdown's first options are always the standard set.
 */
final class RoleOptionsController extends Controller
{
    use AuthorizesUserManagement;

    public function __invoke(Request $request): JsonResponse
    {
        $this->authorizeUsersAccess($request);

        $actor = $request->user();
        $tenantId = $actor?->tenant_id;
        $canAssign = $actor !== null && $actor->can('roles.assign');

        $query = Role::query()->where('guard_name', 'web');

        if ($canAssign && $tenantId !== null) {
            // Both system + this tenant's custom rows.
            $query->where(function (Builder $q) use ($tenantId): void {
                $q->where('is_system', true)
                    ->orWhere('team_id', $tenantId);
            });
        } else {
            // System rows only (the safe default for users with
            // users.invite + users.update but not roles.assign).
            $query->where('is_system', true);
        }

        $roles = $query
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->get(['id', 'name', 'is_system'])
            ->map(static fn (Role $r): array => [
                'id' => $r->id,
                'name' => $r->name,
                'label' => $r->is_system
                    ? __('roles.system.'.$r->name.'.label')
                    : $r->name,
                'is_system' => (bool) $r->is_system,
            ])
            ->all();

        return response()->json(['data' => $roles]);
    }
}
