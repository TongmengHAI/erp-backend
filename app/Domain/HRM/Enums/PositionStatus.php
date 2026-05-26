<?php

declare(strict_types=1);

namespace App\Domain\HRM\Enums;

/**
 * Position lifecycle status.
 *
 * Active   — currently a valid role employees can be assigned to
 * Archived — historical position retained for the audit trail; no
 *            new employees should be assigned. Mirrors the
 *            DepartmentStatus pattern (departments don't get
 *            hard-deleted either).
 *
 * Backed by varchar(16) in the DB with a CHECK constraint mirroring
 * these values.
 */
enum PositionStatus: string
{
    case Active = 'active';
    case Archived = 'archived';
}
