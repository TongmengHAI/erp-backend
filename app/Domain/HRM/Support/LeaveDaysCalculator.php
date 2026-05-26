<?php

declare(strict_types=1);

namespace App\Domain\HRM\Support;

use App\Domain\HRM\Enums\DayPart;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Calendar-days calculator for a leave request — single source of
 * truth for the days_count column.
 *
 * v1 = calendar days. Business-day awareness (weekends, holidays,
 * per-tenant calendar, per-employee shift overrides) is a future
 * slice — not modelled here because none of its inputs exist yet.
 *
 * Why a dedicated module rather than a method on the Action:
 *
 *   1. The migration's backfill UPDATE has to produce the SAME number
 *      for every existing row that the Action would compute today.
 *      Having a code calculator makes that equivalence assertable
 *      ("backfillEqualsCalculator" test) instead of "trust me, the
 *      raw SQL is right."
 *   2. Test surface is direct — no Action setup, no DB transactions,
 *      just compute(start, end, day_part) → float.
 *   3. When business-days lands, this is the natural place for a
 *      strategy interface (CalendarDaysCalculator vs
 *      BusinessDaysCalculator) without churning the Action.
 *
 * Output is a float — Laravel's decimal:1 cast on the model normalises
 * to one fractional digit on the way to/from the DB.
 */
final class LeaveDaysCalculator
{
    /**
     * Compute days_count for a leave request given its date range and
     * day_part. Result is always > 0; the model column is NOT NULL and
     * carries a positive value for every row.
     *
     * Rules:
     *   - FullDay: (end - start + 1) calendar days, inclusive of both
     *     endpoints. Single-day full request = 1.0. Multi-day = N.0.
     *   - Morning / Afternoon: 0.5 by definition. The composite DB
     *     CHECK (leave_requests_day_part_single_date_check) already
     *     enforces start == end for these — the calculator does NOT
     *     re-validate that invariant. If a caller passes start != end
     *     with a half-day part, the higher layers have failed and the
     *     calculator returns 0.5 anyway — the DB row would be rejected
     *     downstream.
     *
     * Inputs accept any DateTimeInterface so callers can pass Carbon,
     * \DateTime, or \DateTimeImmutable interchangeably. The internal
     * normalization uses Carbon's parseDate for stripped-time
     * comparison so a timestamp at 23:59 vs 00:01 doesn't surface as
     * an extra day.
     */
    public function compute(
        DateTimeInterface|string $startDate,
        DateTimeInterface|string $endDate,
        DayPart $dayPart,
    ): float {
        if ($dayPart !== DayPart::FullDay) {
            return 0.5;
        }

        $start = Carbon::parse($startDate)->startOfDay();
        $end = Carbon::parse($endDate)->startOfDay();

        // diffInDays is signed by default; abs() guards against a caller
        // passing start > end. The DB CHECK end >= start makes that
        // un-persistable, but the calculator should still return a sane
        // positive number if called speculatively from a draft form.
        $span = (int) abs($end->diffInDays($start)) + 1;

        return (float) $span;
    }
}
