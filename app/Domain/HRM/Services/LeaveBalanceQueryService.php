<?php

declare(strict_types=1);

namespace App\Domain\HRM\Services;

use App\Domain\HRM\Models\LeaveBalance;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-side encapsulation of the LEFT JOIN that computes
 * remaining_days = allocated_days - SUM(days_count over approved
 * leave_requests for the same employee+type+year).
 *
 * Why a service:
 *
 *   1. Single source of read-time truth. The list endpoint, the show
 *      endpoint, the EmployeeDetailPage "Leave Balances" card, and the
 *      LeaveBalanceDetail page's "Consuming Leave Requests" cross-link
 *      all need consumed_days / remaining_days computed the SAME way.
 *      A method here = one place to change when the rules change.
 *
 *   2. Swap-to-cache later is a one-file change. If real-world scale
 *      ever forces a denormalised cache, this service becomes the
 *      cache reader; the consumers don't change.
 *
 *   3. The decision matrix:
 *        stored  → simpler reads, complex consistency (listener layer,
 *                  reconciliation, race conditions)
 *        compute → always correct, slightly more SQL per read, no cache
 *                  invalidation surface to maintain
 *      We picked compute (Option B in the slice plan). Reasoning:
 *      drift is the worst class of bug for this kind of data; the
 *      SUM is sub-millisecond at SME scale.
 *
 * The query uses a SUBQUERY rather than a correlated subquery per row,
 * so the SUM is evaluated once across the leave_requests table and
 * joined onto the leave_balances rows — efficient even when listing
 * dozens of balance rows.
 *
 * COALESCE(consumed.total_days, 0) handles the no-LR-yet case: an
 * employee with allocated 14 days and zero approved requests reads
 * consumed_days=0, remaining_days=14.0.
 */
final class LeaveBalanceQueryService
{
    /**
     * Returns an Eloquent Builder for LeaveBalance with two computed
     * columns attached: `consumed_days` (float) and `remaining_days`
     * (float). Callers chain ->where()/->paginate()/->get() as usual.
     *
     * Computed columns survive ->paginate() because they're SELECT
     * expressions on the LEFT-JOIN'd derived table. Resource layer
     * reads them as raw attributes.
     *
     * @return Builder<LeaveBalance>
     */
    public function query(): Builder
    {
        return LeaveBalance::query()
            // The aggregate subquery is wrapped in a derived table so
            // the outer GROUP BY isn't needed on leave_balances itself —
            // each balance row gets at most one matched aggregate row
            // (the partial unique index on leave_balances guarantees
            // single-row matches; the GROUP BY in the subquery
            // guarantees single-row aggregates per natural key).
            ->leftJoinSub(
                /** @phpstan-ignore-next-line — SubQueryBuilder builder access pattern */
                LeaveBalance::query()->getConnection()->query()
                    ->from('leave_requests')
                    ->select([
                        'tenant_id',
                        'company_id',
                        'employee_id',
                        'leave_type',
                        // EXTRACT(YEAR FROM start_date) determines the
                        // period_year. Per the slice plan's Q4 decision:
                        // deduct from the year of start_date. A Dec 28 →
                        // Jan 3 request deducts entirely from the start
                        // year's balance.
                    ])
                    ->selectRaw('EXTRACT(YEAR FROM start_date)::int AS period_year')
                    ->selectRaw('SUM(days_count) AS total_days')
                    ->where('status', 'approved')
                    ->whereNull('deleted_at')
                    ->groupBy([
                        'tenant_id',
                        'company_id',
                        'employee_id',
                        'leave_type',
                    ])
                    ->groupByRaw('EXTRACT(YEAR FROM start_date)'),
                'consumed',
                function ($join): void {
                    $join->on('consumed.tenant_id', '=', 'leave_balances.tenant_id')
                        ->on('consumed.company_id', '=', 'leave_balances.company_id')
                        ->on('consumed.employee_id', '=', 'leave_balances.employee_id')
                        ->on('consumed.leave_type', '=', 'leave_balances.leave_type')
                        ->on('consumed.period_year', '=', 'leave_balances.period_year');
                },
            )
            ->select('leave_balances.*')
            // COALESCE: no matching LRs → consumed=0 (LEFT JOIN gives
            // NULL otherwise). Cast to numeric(5,1) keeps it in the
            // same decimal space as allocated_days. The user explicitly
            // asked us to verify this case — it falls naturally out of
            // the COALESCE; an empty result set never trips the
            // days_count > 0 CHECK on leave_requests because the CHECK
            // applies to insert/update, not to a SUM that produced NULL.
            ->selectRaw('COALESCE(consumed.total_days, 0)::numeric(5,1) AS consumed_days')
            // Negative remaining_days is intentional and load-bearing —
            // over-consumption renders as -2.0, not clamped to 0, not
            // an error. The Session 2 UI labels it explicitly
            // ("Over-consumed by 2 days").
            ->selectRaw('(leave_balances.allocated_days - COALESCE(consumed.total_days, 0))::numeric(5,1) AS remaining_days');
    }
}
