<?php

declare(strict_types=1);

namespace App\Web\API\V1\Controllers\SuperAdmin;

use App\Domain\Platform\Actions\CreateTenantWithInitialAdminAction;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\Enums\TenantStatus;
use App\Web\API\V1\Controllers\Controller;
use App\Web\API\V1\Requests\SuperAdmin\StoreTenantRequest;
use App\Web\API\V1\Requests\SuperAdmin\UpdateTenantRequest;
use App\Web\API\V1\Resources\SuperAdmin\TenantBriefResource;
use App\Web\API\V1\Resources\SuperAdmin\TenantFullResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use LogicException;

/**
 * SA-side tenant management endpoints.
 *
 *   GET    /api/v1/super-admin/tenants               — paginated list
 *   GET    /api/v1/super-admin/tenants/{tenant}      — detail
 *   POST   /api/v1/super-admin/tenants               — create + initial admin
 *   PATCH  /api/v1/super-admin/tenants/{tenant}      — update profile + status
 *
 * All routes are gated by the 'super_admin' middleware (404 for non-SA
 * per Q8). The Tenant model itself has no tenant-scoping (it IS the
 * tenant boundary); SA's TenantScope/CompanyScope bypasses + the lack
 * of tenant_id on Tenant mean cross-tenant reads work without any
 * special bypass logic in this controller.
 */
class TenantController extends Controller
{
    /**
     * Paginated tenant list. Supports an optional `status` filter
     * (per Q7 — show-all-with-badge + URL filter chip on the SPA).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'string', 'in:active,suspended,archived'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Tenant::query()->orderByDesc('created_at');

        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $perPage = $validated['per_page'] ?? 25;

        return TenantBriefResource::collection($query->paginate($perPage));
    }

    /** Detail. Route-model binding resolves the {tenant} id. */
    public function show(Tenant $tenant): TenantFullResource
    {
        return new TenantFullResource($tenant);
    }

    /**
     * Create a tenant + initial admin user atomically. Returns the new
     * tenant + the one-time admin password (the SPA displays this
     * exactly once and never re-fetches; SA copies to deliver to the
     * tenant admin, future resets via the standard password-reset flow).
     */
    public function store(StoreTenantRequest $request, CreateTenantWithInitialAdminAction $action): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw new LogicException('User expected on a route protected by auth:sanctum.');
        }

        /** @var array{slug: string, name: string, legal_name?: string|null, country_code: string, default_currency: string, functional_currency: string, timezone: string, company: array{slug: string, name: string, legal_name?: string|null}, initial_admin: array{name: string, email: string}} $data */
        $data = $request->validated();

        $result = $action->execute(
            tenantData: [
                'slug' => $data['slug'],
                'name' => $data['name'],
                'legal_name' => $data['legal_name'] ?? null,
                'country_code' => $data['country_code'],
                'default_currency' => $data['default_currency'],
                'functional_currency' => $data['functional_currency'],
                'timezone' => $data['timezone'],
            ],
            companyData: [
                'slug' => $data['company']['slug'],
                'name' => $data['company']['name'],
                'legal_name' => $data['company']['legal_name'] ?? null,
            ],
            adminData: [
                'name' => $data['initial_admin']['name'],
                'email' => $data['initial_admin']['email'],
            ],
            actingSuperAdmin: $user,
        );

        // The response is the ONLY place the plaintext password appears.
        // It is NOT logged, audited, or persisted elsewhere — by virtue
        // of Auditable's filterAttributesForAudit dropping User::$hidden
        // keys, the audit_logs row for the User creation captures the
        // event without the secret.
        return response()->json([
            'data' => [
                'tenant' => new TenantFullResource($result->tenant),
                'initial_admin' => [
                    'id' => $result->admin->id,
                    'name' => $result->admin->name,
                    'email' => $result->admin->email,
                ],
                'initial_admin_password' => $result->initialAdminPassword,
            ],
        ], 201);
    }

    /**
     * Update tenant profile + status. PATCH semantics — only the fields
     * in the payload are touched.
     *
     * Status transitions (active ↔ suspended) audit-log naturally via
     * the Auditable trait on Tenant. Suspending a tenant takes effect
     * on the AFFECTED users' NEXT request: ResolveTenant reads
     * tenants.status, throws TenantInactiveException → 401
     * error_code=tenant_inactive, SPA routes to /tenant-suspended. No
     * explicit session-killer needed (per Q1).
     */
    public function update(UpdateTenantRequest $request, Tenant $tenant): TenantFullResource
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();

        // Status field arrives as a string; cast to enum for type safety.
        if (isset($data['status'])) {
            $data['status'] = TenantStatus::from($data['status']);
        }

        $tenant->update($data);

        return new TenantFullResource($tenant);
    }
}
