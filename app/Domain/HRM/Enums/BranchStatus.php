<?php

declare(strict_types=1);

namespace App\Domain\HRM\Enums;

/**
 * Branch lifecycle status. Mirrors PositionStatus + DepartmentStatus —
 * the canonical two-value HRM lifecycle shape.
 *
 * Active   — currently operational; employees can be assigned
 * Archived — closed location retained for historical attribution
 *
 * Backed by varchar(16) in the DB with a CHECK constraint mirroring
 * these values.
 */
enum BranchStatus: string
{
    case Active = 'active';
    case Archived = 'archived';
}
