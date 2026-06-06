<?php

declare(strict_types=1);

namespace App\Web\API\V1\Controllers\Permissions;

use App\Web\API\V1\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Spatie\Permission\Models\Permission;

/**
 * GET /api/v1/permissions/descriptions.
 *
 * Returns the project's permission + domain descriptions catalog
 * (sourced from resources/lang/<locale>/permissions.php) as two flat
 * maps under data.
 *
 *   data.domains.{domain_key}        = "Display label"
 *   data.permissions.{full_name}     = "Display label"
 *   data.permission_ids.{full_name}  = integer id
 *
 * Consumed by the SPA's PermissionPicker (Session 4) and
 * PermissionList (Session 4) components for human-readable labels.
 * Raw permission names (e.g. hrm.employee.view) are hidden from UI
 * per Phase 2B Q18 — only the readable labels render.
 *
 * permission_ids is the name → id mapping the PermissionPicker
 * needs to render the FULL catalog (not just the role's currently-
 * assigned subset) and emit ID arrays at submit time. Added in
 * Phase 2B Session 4 alongside the picker — additive, no existing
 * test broken.
 *
 * Auth: authenticated users only. No specific permission gate —
 * descriptions are not sensitive (they reveal which permissions
 * exist, but the existence is already implied by /auth/me's
 * permissions array shape, and viewer-role users with roles.view
 * need this catalog to render a meaningful read-only permission
 * view in Session 4).
 *
 * Cache: descriptions are static per deploy + locale. The SPA
 * consumer caches with staleTime: Infinity. No server-side cache
 * — translation files are loaded once per worker by Laravel's
 * translator and effectively cached in-process.
 *
 * Static — no state, no Action delegation. Inline per CLAUDE.md
 * §B trivial threshold.
 */
final class PermissionDescriptionsController extends Controller
{
    public function __invoke(): JsonResponse
    {
        /** @var array<string, string> $domains */
        $domains = (array) trans('permissions.domains');
        /** @var array<string, string> $permissions */
        $permissions = (array) trans('permissions.permissions');

        /** @var array<string, int> $permissionIds */
        $permissionIds = Permission::query()
            ->pluck('id', 'name')
            ->map(static fn (int|string $id): int => (int) $id)
            ->all();

        return response()->json([
            'data' => [
                'domains' => $domains,
                'permissions' => $permissions,
                'permission_ids' => $permissionIds,
            ],
        ]);
    }
}
