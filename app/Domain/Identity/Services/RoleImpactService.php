<?php

declare(strict_types=1);

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Computes the user-impact preview that the SPA shows before saving a
 * permission removal on a custom role.
 *
 * OVER-WARN SEMANTIC (Phase 2B locked decision, plan Q5):
 *
 *   Count = number of users CURRENTLY ASSIGNED $role. NOT "users who
 *   would lose effective coverage of $removedPermissions after the
 *   save," which would require a cross-role coverage check ("does this
 *   user have another role that covers the permission?") and a more
 *   expensive query.
 *
 *   The count may OVER-REPORT when users have permissions via multiple
 *   roles — that is INTENTIONAL. Over-warning is safer than under-
 *   warning: the admin makes the right decision either way (cancel +
 *   investigate vs. proceed). Under-warning would suggest a permission
 *   removal was harmless when it actually impacted a user.
 *
 *   DO NOT "FIX" THIS by adding the cross-role coverage check. Future
 *   Claude Code session reading this comment: the simpler query is
 *   the locked decision, not an oversight. If the over-warning
 *   becomes a UX problem in practice, the right move is a separate
 *   "advanced impact" endpoint (different URL, different response
 *   shape), not retrofitting this one.
 *
 * Read-side only — no writes. Computes:
 *   - affected_users_count : exact count from model_has_roles
 *   - affected_users_preview : up to 5 user rows (id + name), for the
 *     dialog's "X, Y, Z, and 2 others" rendering. NOT paginated; the
 *     dialog is informational, not navigable. Preview is sorted by
 *     name for stable rendering across requests.
 */
final class RoleImpactService
{
    /**
     * @param  list<string>  $removedPermissions
     * @return array{affected_users_count: int, affected_users_preview: list<array{id: int, name: string}>}
     */
    public function compute(Role $role, array $removedPermissions): array
    {
        // The over-warn semantic doesn't actually consume
        // $removedPermissions for the count — the count is "users
        // currently assigned this role." The parameter is kept on the
        // method signature for API symmetry with a potential future
        // "advanced impact" endpoint that DOES use it.
        unset($removedPermissions);

        $count = (int) DB::table('model_has_roles')
            ->where('role_id', $role->id)
            ->where('model_type', User::class)
            ->count();

        if ($count === 0) {
            return [
                'affected_users_count' => 0,
                'affected_users_preview' => [],
            ];
        }

        /** @var list<array{id: int, name: string}> $preview */
        $preview = User::query()
            ->whereIn(
                'id',
                DB::table('model_has_roles')
                    ->where('role_id', $role->id)
                    ->where('model_type', User::class)
                    ->pluck('model_id')
            )
            ->orderBy('name')
            ->limit(5)
            ->get(['id', 'name'])
            ->map(static fn (User $u): array => ['id' => $u->id, 'name' => $u->name])
            ->all();

        return [
            'affected_users_count' => $count,
            'affected_users_preview' => $preview,
        ];
    }
}
