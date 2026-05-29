<?php

declare(strict_types=1);

namespace App\Web\API\V1\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Per-request guard that restricts a route group to super_admin users.
 * Applied to the /api/v1/super-admin/* group.
 *
 * Behaviour:
 *   - unauthenticated      → already handled upstream by auth:sanctum
 *                            (401); SuperAdminGuard never sees these
 *   - tenant_user (auth'd) → 404 NotFoundHttpException
 *   - super_admin          → continue to route
 *
 * Per Q8 of the locked design decisions: 404 (not 403) for non-SA
 * accessing SA endpoints. Security through obscurity — tenant_users
 * have no legitimate reason to type /super-admin/* URLs; treating
 * those as nonexistent for unauthorized accessors is the right posture.
 * Matches the convention SaaS admin tools follow (GitHub Enterprise
 * admin, Stripe Dashboard internals).
 */
final class SuperAdminGuard
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->isSuperAdmin()) {
            // 404 — the route effectively doesn't exist for them.
            throw new NotFoundHttpException;
        }

        return $next($request);
    }
}
