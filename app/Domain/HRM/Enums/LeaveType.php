<?php

declare(strict_types=1);

namespace App\Domain\HRM\Enums;

/**
 * Leave request type.
 *
 * Annual — paid annual leave, draws from the (future) leave-balance pool
 * Sick   — sick leave; usually a separate balance with different rules
 * Unpaid — leave without pay; doesn't draw from any balance
 * Other  — catch-all for bereavement, maternity/paternity, sabbatical etc.
 *          Bundling these as "Other" is a deliberate scope cut — when each
 *          gets distinct policy rules, they split into their own enum cases.
 *
 * Backed by varchar(16) in the DB with a CHECK constraint mirroring these
 * values (see 2026_05_22_100000_create_leave_requests_table.php).
 */
enum LeaveType: string
{
    case Annual = 'annual';
    case Sick = 'sick';
    case Unpaid = 'unpaid';
    case Other = 'other';
}
