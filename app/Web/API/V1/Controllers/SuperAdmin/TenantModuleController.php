<?php

declare(strict_types=1);

namespace App\Web\API\V1\Controllers\SuperAdmin;

use App\Domain\Platform\Enums\ModuleStatus;
use App\Domain\Platform\Models\TenantModule;
use App\Models\Tenant;
use App\Models\User;
use App\Web\API\V1\Controllers\Controller;
use App\Web\API\V1\Requests\SuperAdmin\SyncTenantModulesRequest;
use App\Web\API\V1\Resources\SuperAdmin\TenantModuleResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * SA-side per-tenant module entitlement endpoints.
 *
 * Mounted at:
 *   GET   /api/v1/super-admin/tenants/{tenant}/modules
 *   PATCH /api/v1/super-admin/tenants/{tenant}/modules
 *
 * Both routes are gated by the 'super_admin' middleware (404 for non-SA).
 * Route-model binding resolves {tenant} to a Tenant model — SA can read
 * any tenant (including suspended ones, per Q1) because Tenant has no
 * tenant-scoping, and the SA's TenantScope/CompanyScope bypasses leave
 * cross-tenant queries on TenantModule unrestricted.
 */
class TenantModuleController extends Controller
{
    /**
     * List the current entitlement rows for a tenant. Soft-deleted rows
     * are excluded (use ->withTrashed() in a future audit-trail endpoint
     * if that becomes useful).
     */
    public function index(Request $request, Tenant $tenant): AnonymousResourceCollection
    {
        $modules = TenantModule::query()
            ->acrossTenants() // SA endpoint; route-param tenant is the filter
            ->where('tenant_id', $tenant->id)
            ->orderBy('module_key')
            ->get();

        return TenantModuleResource::collection($modules);
    }

    /**
     * Reconcile the tenant's entitlement to the provided desired state.
     *
     * For each (module_key, status) pair in the payload:
     *   - If a row exists, update its status (and bump enabled_by_user_id
     *     + enabled_at when flipping to Active).
     *   - If no row exists, create one.
     *   - Soft-deleted rows are restored if they match a payload entry
     *     (preserves the audit-history chain).
     *
     * Modules NOT in the payload are left untouched. Sync is partial-
     * update: a payload with just `[{module_key: 'hrm', status: 'disabled'}]`
     * disables HRM but doesn't touch any other entitlement row.
     */
    public function sync(SyncTenantModulesRequest $request, Tenant $tenant): AnonymousResourceCollection
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw new LogicException('User expected on a route protected by auth:sanctum.');
        }

        /** @var array{modules: list<array{module_key: string, status: string}>} $validated */
        $validated = $request->validated();

        DB::transaction(function () use ($tenant, $user, $validated): void {
            foreach ($validated['modules'] as $entry) {
                $status = ModuleStatus::from($entry['status']);

                /** @var TenantModule|null $existing */
                $existing = TenantModule::query()
                    ->acrossTenants()
                    ->withTrashed()
                    ->where('tenant_id', $tenant->id)
                    ->where('module_key', $entry['module_key'])
                    ->first();

                if ($existing === null) {
                    TenantModule::query()->create([
                        'tenant_id' => $tenant->id,
                        'module_key' => $entry['module_key'],
                        'status' => $status,
                        'enabled_at' => $status === ModuleStatus::Active ? now() : null,
                        'enabled_by_user_id' => $status === ModuleStatus::Active ? $user->id : null,
                    ]);

                    continue;
                }

                // Restore soft-deleted row if we're touching it.
                if ($existing->trashed()) {
                    $existing->restore();
                }

                $attrs = ['status' => $status];

                // When flipping to Active, refresh enabled_at + the
                // actor. When flipping to Disabled, leave them as
                // historical record (the next Active flip will refresh
                // them).
                if ($status === ModuleStatus::Active) {
                    $attrs['enabled_at'] = now();
                    $attrs['enabled_by_user_id'] = $user->id;
                }

                $existing->update($attrs);
            }
        });

        $modules = TenantModule::query()
            ->acrossTenants() // SA endpoint; route-param tenant is the filter
            ->where('tenant_id', $tenant->id)
            ->orderBy('module_key')
            ->get();

        return TenantModuleResource::collection($modules);
    }
}
