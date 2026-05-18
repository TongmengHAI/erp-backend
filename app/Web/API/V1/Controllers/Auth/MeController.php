<?php

declare(strict_types=1);

namespace App\Web\API\V1\Controllers\Auth;

use App\Models\Company;
use App\Models\User;
use App\Support\Company\CompanyContext;
use App\Support\Company\Enums\CompanyStatus;
use App\Support\Tenancy\TenantContext;
use App\Web\API\V1\Controllers\Controller;
use App\Web\API\V1\Resources\CompanyBriefResource;
use App\Web\API\V1\Resources\CompanyResource;
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
 *   3. current_company comes from CompanyContext (set by ResolveCompany,
 *      which is registered with route meta companyOptional=true here so a
 *      user with no resolvable company still gets a graceful response that
 *      lets the SPA render a company-picker UI).
 *   4. companies[] lists every active company in the user's tenant — brief
 *      shape, sufficient to render the switcher.
 *   5. roles + permissions come from Spatie HasRoles, scoped to the tenant
 *      (H1a). Per-company permission scoping is H1c's responsibility.
 *
 * The route is protected by auth:sanctum + tenant — those throw if
 * unauthenticated / tenant_inactive / orphaned. The company middleware
 * runs with companyOptional=true so current_company may be null in the
 * response payload (renders empty picker on the SPA).
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

        $currentCompany = app(CompanyContext::class)->current();

        $companies = Company::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', CompanyStatus::Active->value)
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => [
                'user' => new UserResource($user),
                'tenant' => new TenantResource($tenant),
                'current_company' => $currentCompany !== null
                    ? new CompanyResource($currentCompany)
                    : null,
                'companies' => CompanyBriefResource::collection($companies),
                'roles' => $user->getRoleNames()->values()->all(),
                'permissions' => $user->getAllPermissions()->pluck('name')->values()->all(),
            ],
        ]);
    }
}
