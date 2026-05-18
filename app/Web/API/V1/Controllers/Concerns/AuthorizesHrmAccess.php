<?php

declare(strict_types=1);

namespace App\Web\API\V1\Controllers\Concerns;

use Illuminate\Http\Request;

/**
 * ─────────────────────────────────────────────────────────────────────────────
 * HRM authorization chokepoint.
 *
 * Every HRM controller MUST flow permission checks through `authorizeHrm()`.
 * Do NOT call any of these in HRM controllers:
 *
 *   ❌  $user->hasRole('tenant_admin')
 *   ❌  $user->permissions->contains(...)
 *   ❌  if ($user->is_admin) { ... }
 *   ❌  Gate::allows(...) without a registered policy that itself calls can()
 *
 * Why: Spatie's `can()` reads through the team scope. The current team scope
 * is tenant_id; the H1c slice (not built for the graded demo) re-anchors that
 * to a (tenant_id, company_id) composite. Anything that bypasses can() will
 * silently leak across companies once that wiring lands. The chokepoint
 * makes H1c a no-op refactor — flip the team-resolver, and every HRM
 * endpoint's authorization is correct without code changes.
 *
 * 403 is the right status: the user authenticated (101 still works) but
 * lacks the specific permission. The body comes from Laravel's default
 * abort handler — no need to customise.
 * ─────────────────────────────────────────────────────────────────────────────
 */
trait AuthorizesHrmAccess
{
    protected function authorizeHrm(Request $request, string $permission): void
    {
        $user = $request->user();
        if ($user === null || ! $user->can($permission)) {
            abort(403);
        }
    }
}
