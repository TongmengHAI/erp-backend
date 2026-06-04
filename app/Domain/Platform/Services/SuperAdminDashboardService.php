<?php

declare(strict_types=1);

namespace App\Domain\Platform\Services;

use App\Domain\Platform\Enums\ModuleStatus;
use App\Models\Tenant;
use App\Support\Tenancy\Enums\TenantStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * SA-side dashboard read-side service. Single source of read-time truth
 * for the dashboard's 5 metric tiles + 2 recent-activity lists (per Q6).
 *
 * Per §10.3 computed-state pattern: every consumer (currently
 * DashboardController; future cache-warming jobs or background reports)
 * routes through this service. Swap-to-cache later — e.g. Redis-backed,
 * since the metrics change at most a few times per day — becomes a
 * single-file change; consumers remain unchanged.
 *
 * Query strategy (Session 4 plan tightening #1): separate query per
 * metric (5 metric queries + 2 list queries = 7 round-trips per
 * dashboard load). Each metric is independently cacheable later. The
 * alternative (one combined query) is more efficient but couples the
 * metrics together — caching one without the others becomes awkward,
 * and the dashboard's load profile (~7 queries; all indexed; sub-100ms
 * total in practice) doesn't justify the optimisation yet.
 *
 * Cross-tenant by design — every query bypasses TenantScope via
 * acrossTenants() / withoutGlobalScopes(). The dashboard is platform-
 * level; the SA is reading aggregate state across the entire vendor
 * estate.
 */
final class SuperAdminDashboardService
{
    /**
     * Days included in "recent" windows. Constants here so the
     * Resource and tests don't drift from the service's definition.
     */
    public const RECENT_WINDOW_DAYS = 7;

    /**
     * Top-N cap on the recent-activity lists. The dashboard tiles show
     * a finite list (Q6: "top 10"); larger sets are out of scope for
     * v1 (the SA can drill into the full tenants list via the existing
     * /super-admin/tenants endpoint with a status filter).
     */
    public const RECENT_LIST_LIMIT = 10;

    /**
     * Tile #1 + #2: tenant counts grouped by status. Returns
     * { total: int, active: int, suspended: int, archived: int }.
     *
     * One query (GROUP BY status). The "Total tenants" tile reads
     * `total` + uses `active`/`suspended` for the breakdown badge;
     * the "Active tenants" tile reads `active`. Two tiles, one query.
     *
     * @return array{total: int, active: int, suspended: int, archived: int}
     */
    public function tenantStatusCounts(): array
    {
        // Raw DB::table for the aggregation — avoids the Eloquent
        // status enum cast (which Eloquent\Collection::map can't
        // narrow back to a plain string for the phantom 'c' alias).
        // The tenants table is platform-level (no global scope), so
        // DB::table is a clean read with no scope concerns.
        $byStatus = DB::table('tenants')
            ->whereNull('deleted_at')
            ->selectRaw('status, COUNT(*) AS c')
            ->groupBy('status')
            ->get()
            ->mapWithKeys(fn (object $row): array => [(string) $row->status => (int) $row->c])
            ->all();

        $active = $byStatus[TenantStatus::Active->value] ?? 0;
        $suspended = $byStatus[TenantStatus::Suspended->value] ?? 0;
        $archived = $byStatus[TenantStatus::Archived->value] ?? 0;

        return [
            'total' => $active + $suspended + $archived,
            'active' => $active,
            'suspended' => $suspended,
            'archived' => $archived,
        ];
    }

    /**
     * Tile #3: tenants-per-module counts. For each known module key,
     * returns active + disabled counts. v1 returns just HRM (the only
     * module shipped); future modules append by adding to LAUNCHER_APPS
     * AND to this query (the module_key allowlist on EnforceModuleEntitlement
     * stays the single source of truth for what counts as a module).
     *
     * Soft-deleted tenant_modules rows are EXCLUDED (they represent
     * historical revocation, not current entitlement).
     *
     * @return list<array{module_key: string, active_count: int, disabled_count: int}>
     */
    public function tenantsByModule(): array
    {
        // Same DB::table approach as tenantStatusCounts — avoids the
        // status enum cast for the GROUP BY result.
        $rows = DB::table('tenant_modules')
            ->whereNull('deleted_at')
            ->selectRaw('module_key, status, COUNT(*) AS c')
            ->groupBy('module_key', 'status')
            ->get();

        // Pivot rows → { module_key => { active_count, disabled_count } }.
        $byModule = [];
        foreach ($rows as $row) {
            $key = (string) $row->module_key;
            $byModule[$key] ??= ['active_count' => 0, 'disabled_count' => 0];
            if ($row->status === ModuleStatus::Active->value) {
                $byModule[$key]['active_count'] = (int) $row->c;
            } elseif ($row->status === ModuleStatus::Disabled->value) {
                $byModule[$key]['disabled_count'] = (int) $row->c;
            }
        }

        $result = [];
        foreach ($byModule as $key => $counts) {
            $result[] = [
                'module_key' => $key,
                'active_count' => $counts['active_count'],
                'disabled_count' => $counts['disabled_count'],
            ];
        }

        return $result;
    }

    /**
     * Tile #4: tenants created in the last RECENT_WINDOW_DAYS, top
     * RECENT_LIST_LIMIT by created_at DESC. Used by the SPA to render
     * a list of recent signups with a click-through to the tenant
     * detail page.
     *
     * @return Collection<int, Tenant>
     */
    public function recentSignups(): Collection
    {
        return Tenant::query()
            ->withoutGlobalScopes()
            ->where('created_at', '>=', Carbon::now()->subDays(self::RECENT_WINDOW_DAYS))
            ->orderByDesc('created_at')
            ->limit(self::RECENT_LIST_LIMIT)
            ->get();
    }

    /**
     * Tile #5: tenants currently in the Suspended state where the
     * status flipped recently. v1 uses updated_at as a proxy for
     * "when suspended" — assumes the most recent status change is the
     * suspension, which is true in the SA's flow (SA flips status
     * only via PATCH which bumps updated_at). When that assumption
     * breaks (e.g. a tenant gets suspended then has unrelated profile
     * updates), switch to an audit_logs query — but that's a future
     * concern, not v1.
     *
     * @return Collection<int, Tenant>
     */
    public function recentSuspensions(): Collection
    {
        return Tenant::query()
            ->withoutGlobalScopes()
            ->where('status', TenantStatus::Suspended->value)
            ->where('updated_at', '>=', Carbon::now()->subDays(self::RECENT_WINDOW_DAYS))
            ->orderByDesc('updated_at')
            ->limit(self::RECENT_LIST_LIMIT)
            ->get();
    }
}
