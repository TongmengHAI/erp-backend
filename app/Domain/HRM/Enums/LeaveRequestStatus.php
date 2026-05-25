<?php

declare(strict_types=1);

namespace App\Domain\HRM\Enums;

/**
 * Leave request workflow state.
 *
 * State machine:
 *   (none) ──create──► Pending ──approve──► Approved (terminal)
 *                          │
 *                          └────reject────► Rejected (terminal)
 *
 * Pending  — newly created; editable by .update; transitionable by .approve
 * Approved — terminal in this slice; soft-delete still allowed via .delete
 * Rejected — terminal in this slice; soft-delete still allowed via .delete
 *
 * Reopen / revise after decision is explicitly out of scope — that's a
 * real product feature with its own audit story (who reopened, why,
 * what changed). Stays as a future slice.
 *
 * The (status, approved_by, approved_at) composite CHECK at the DB
 * layer enforces that pending rows have null approval columns and
 * decided rows have both populated. Application-layer enforcement
 * lives in CreateLeaveRequestAction (forces Pending) and the Approve/
 * Reject actions (assert current state is Pending before transition).
 *
 * Backed by varchar(16) in the DB with a CHECK constraint mirroring
 * these values.
 */
enum LeaveRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
