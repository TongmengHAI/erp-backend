<?php

declare(strict_types=1);

namespace App\Web\API\V1\Resources\SuperAdmin;

use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Output shape for GET /api/v1/super-admin/dashboard.
 *
 * Wraps a SuperAdminDashboardResult-shaped associative array (assembled
 * by DashboardController). Five metric blocks + two recent-activity
 * lists per Q6:
 *
 *   {
 *     "data": {
 *       "tenant_status_counts": { "total": N, "active": N, "suspended": N, "archived": N },
 *       "tenants_by_module":    [{ "module_key": "hrm", "active_count": N, "disabled_count": N }],
 *       "recent_signups":       [TenantBrief, ...],
 *       "recent_suspensions":   [TenantBrief, ...],
 *       "window_days":          7
 *     }
 *   }
 *
 * `window_days` mirrors SuperAdminDashboardService::RECENT_WINDOW_DAYS;
 * the SPA uses it for the section heading ("Last 7 days") so the
 * service's window definition isn't hardcoded into the frontend copy.
 *
 * The recent_signups + recent_suspensions lists embed the same brief
 * Tenant shape used by TenantBriefResource (id, slug, name, status,
 * country_code, created_at). Reusing the existing resource keeps the
 * SPA's tenant-card component a single source of truth.
 *
 * @phpstan-type DashboardResultArray array{
 *   tenant_status_counts: array{total: int, active: int, suspended: int, archived: int},
 *   tenants_by_module: list<array{module_key: string, active_count: int, disabled_count: int}>,
 *   recent_signups: \Illuminate\Support\Collection<int, Tenant>,
 *   recent_suspensions: \Illuminate\Support\Collection<int, Tenant>,
 *   window_days: int,
 * }
 *
 * @property-read DashboardResultArray $resource
 */
class DashboardResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var DashboardResultArray $data */
        $data = $this->resource;

        return [
            'tenant_status_counts' => $data['tenant_status_counts'],
            'tenants_by_module' => $data['tenants_by_module'],
            'recent_signups' => TenantBriefResource::collection($data['recent_signups']),
            'recent_suspensions' => TenantBriefResource::collection($data['recent_suspensions']),
            'window_days' => $data['window_days'],
        ];
    }
}
