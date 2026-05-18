<?php

declare(strict_types=1);

namespace App\Domain\HRM\Enums;

/**
 * Department lifecycle status.
 *
 * Active   — operational; appears in default lists, assignable to employees
 *            (when the Employee↔Department FK lands in a future slice).
 * Archived — retired; preserved for historical reference. Filtered out of the
 *            UI's default list view but still listable via the status filter.
 *
 * Backed by varchar(16) in the DB with a CHECK constraint mirroring these
 * values (see 2026_05_20_100000_create_departments_table.php).
 *
 * Mirrors CompanyStatus's two-value shape — departments don't have an
 * interim "on leave" state the way employees do; they're either in use
 * or retired.
 */
enum DepartmentStatus: string
{
    case Active = 'active';
    case Archived = 'archived';
}
