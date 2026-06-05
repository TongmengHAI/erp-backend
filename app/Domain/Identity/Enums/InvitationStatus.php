<?php

declare(strict_types=1);

namespace App\Domain\Identity\Enums;

/**
 * Computed state for the Invitation model — NOT a stored column.
 *
 * Resolution order matters (history wins):
 *
 *   accepted   — accepted_at !== null. Terminal.
 *   cancelled  — cancelled_at !== null. Terminal.
 *   expired    — expires_at < now (and not accepted/cancelled). Terminal.
 *   pending    — none of the above.
 *
 * Per CLAUDE.md §10.3 (computed-state default), the Invitation model
 * exposes status as an accessor; the InvitationQueryService selects an
 * equivalent SQL CASE WHEN expression for query-time filtering. No
 * stored status column means no drift surface.
 */
enum InvitationStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
}
