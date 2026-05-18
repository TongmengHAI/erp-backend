<?php

declare(strict_types=1);

namespace App\Domain\HRM\Enums;

/**
 * Employee employment status.
 *
 * Active     — currently working, on payroll
 * OnLeave    — temporarily away (parental, medical, sabbatical) but still
 *              employed. Excluded from some reports but counted in headcount.
 * Terminated — no longer employed. Soft-deleted records retain the historical
 *              status; rows are NOT auto-terminated on soft delete.
 *
 * Backed by varchar(16) in the DB with a CHECK constraint mirroring these
 * values (see 2026_05_19_100000_create_employees_table.php).
 */
enum EmployeeStatus: string
{
    case Active = 'active';
    case OnLeave = 'on_leave';
    case Terminated = 'terminated';
}
