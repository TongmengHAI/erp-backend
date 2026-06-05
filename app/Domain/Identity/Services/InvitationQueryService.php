<?php

declare(strict_types=1);

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Enums\InvitationStatus;
use App\Domain\Identity\Models\Invitation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Read-side service for Invitations. Routes through this service for
 * any consumer that needs the computed `status` column for filtering /
 * sorting (admin invitation list, dashboard counts).
 *
 * The SQL CASE WHEN below mirrors Invitation::status() exactly —
 * accepted > cancelled > expired > pending. Drift between the two
 * surfaces breaks status filter results, so any change to one MUST
 * propagate to the other. The InvitationStatusComputedStateConsistencyTest
 * (see test suite) pins the two implementations agree across a property
 * of synthetic invitation rows (per §10.15).
 */
final class InvitationQueryService
{
    /**
     * Returns a builder that selects the computed status as `status_computed`
     * alongside the standard columns. Caller composes `where('status_computed', ...)`
     * for filtering.
     *
     * @return Builder<Invitation>
     */
    public function query(): Builder
    {
        return Invitation::query()->select([
            'invitations.*',
            DB::raw(
                'CASE '
                ."WHEN accepted_at IS NOT NULL THEN '".InvitationStatus::Accepted->value."' "
                ."WHEN cancelled_at IS NOT NULL THEN '".InvitationStatus::Cancelled->value."' "
                ."WHEN expires_at < NOW() THEN '".InvitationStatus::Expired->value."' "
                ."ELSE '".InvitationStatus::Pending->value."' "
                .'END AS status_computed'
            ),
        ]);
    }
}
