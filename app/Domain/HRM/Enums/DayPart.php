<?php

declare(strict_types=1);

namespace App\Domain\HRM\Enums;

/**
 * Day-part granularity for a leave request.
 *
 * FullDay   — request spans entire workdays (can be a single date or a
 *             multi-date range). Default and overwhelmingly common.
 * Morning   — half-day request, morning only. By construction
 *             start_date == end_date.
 * Afternoon — half-day request, afternoon only. Same single-date
 *             constraint.
 *
 * The single-date invariant for Morning/Afternoon is enforced at three
 * layers (defense in depth, same triple-stack as the approval-
 * consistency CHECK):
 *
 *   1. Zod refinement on the frontend form
 *   2. Closure rule on Store/Update FormRequests (422 errors.end_date)
 *   3. Composite DB CHECK constraint
 *
 * Hourly granularity (e.g. "Tuesday 9am–12pm") is deliberately NOT
 * modeled here — that's a different feature with its own units (hours
 * vs days), time zones, working-hours config, and overlap detection.
 * If hourly leave is ever needed it lands as a separate module
 * (likely "Time Off (hourly)" or Time Tracking).
 *
 * Backed by varchar(16) in the DB with a CHECK constraint mirroring
 * these values (see 2026_05_27_*_add_day_part_to_leave_requests.php).
 */
enum DayPart: string
{
    case FullDay = 'full_day';
    case Morning = 'morning';
    case Afternoon = 'afternoon';
}
