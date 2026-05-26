<?php

declare(strict_types=1);

namespace App\Domain\HRM\Enums;

/**
 * Attendance status — admin-recorded label for what happened on a date.
 *
 * Present  — employee was present for their normal hours
 * Absent   — employee was not present (no clock times typical)
 * Late     — employee was present but clocked in after expected start
 * OnLeave  — employee was on leave (separate from the Leave Requests
 *            workflow at this slice — see hrm.md "Attendance and Leave
 *            Requests" subsection for the coupling decision)
 * HalfDay  — employee worked a partial day (one of the clock times may
 *            be present, the other null, depending on AM/PM)
 *
 * The relationship between status='on_leave' and the Leave Requests
 * module is deliberately uncoupled at this slice (option a from the
 * plan): status is a manual label, not derived from approved leave
 * requests. The Leave Balances slice (v1 path slice 4) introduces
 * the integration naturally.
 *
 * Backed by varchar(16) in the DB with a CHECK constraint mirroring
 * these values (see 2026_05_29_*_create_attendance_records_table.php).
 */
enum AttendanceStatus: string
{
    case Present = 'present';
    case Absent = 'absent';
    case Late = 'late';
    case OnLeave = 'on_leave';
    case HalfDay = 'half_day';
}
