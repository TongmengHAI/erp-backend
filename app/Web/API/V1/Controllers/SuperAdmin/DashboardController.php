<?php

declare(strict_types=1);

namespace App\Web\API\V1\Controllers\SuperAdmin;

use App\Domain\Platform\Services\SuperAdminDashboardService;
use App\Web\API\V1\Controllers\Controller;
use App\Web\API\V1\Resources\SuperAdmin\DashboardResource;

/**
 * GET /api/v1/super-admin/dashboard
 *
 * Thin controller — assembles the dashboard payload by routing every
 * metric query through SuperAdminDashboardService (§10.3 read-side
 * pattern). No business logic here; just composition.
 *
 * Gated upstream by 'super_admin' middleware (404 for non-SA per Q8).
 */
class DashboardController extends Controller
{
    public function __invoke(SuperAdminDashboardService $service): DashboardResource
    {
        return new DashboardResource([
            'tenant_status_counts' => $service->tenantStatusCounts(),
            'tenants_by_module' => $service->tenantsByModule(),
            'recent_signups' => $service->recentSignups(),
            'recent_suspensions' => $service->recentSuspensions(),
            'window_days' => SuperAdminDashboardService::RECENT_WINDOW_DAYS,
        ]);
    }
}
