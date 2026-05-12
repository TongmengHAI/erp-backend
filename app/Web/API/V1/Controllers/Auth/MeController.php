<?php

declare(strict_types=1);

namespace App\Web\API\V1\Controllers\Auth;

use App\Models\User;
use App\Support\Tenancy\TenantContext;
use App\Web\API\V1\Controllers\Controller;
use App\Web\API\V1\Resources\TenantResource;
use App\Web\API\V1\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;

/**
 * GET /api/v1/auth/me — returns the authenticated user's current auth context.
 *
 * Inline (no Action delegation) per §B "trivial = under 50 LOC":
 *   1. user comes from $request->user()  (set by auth:sanctum middleware)
 *   2. tenant comes from TenantContext   (set by ResolveTenant middleware)
 *   3. roles + permissions come from Spatie HasRoles, automatically scoped to
 *      the current tenant because ResolveTenant set the registrar team_id.
 *
 * Both middlewares throw before this controller runs if their invariants
 * fail (401 unauthenticated / 401 tenant_inactive / 403 not-a-member), so
 * the defensive instanceof + null checks below should be unreachable —
 * but a LogicException is preferable to a silent null-deref if they ever do.
 */
final class MeController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw new LogicException('User expected on a route protected by auth:sanctum.');
        }

        $tenant = app(TenantContext::class)->current();
        if ($tenant === null) {
            throw new LogicException('Tenant expected on a route protected by ResolveTenant.');
        }

        return response()->json([
            'data' => [
                'user' => new UserResource($user),
                'tenant' => new TenantResource($tenant),
                'roles' => $user->getRoleNames()->values()->all(),
                'permissions' => $user->getAllPermissions()->pluck('name')->values()->all(),
            ],
        ]);
    }
}
