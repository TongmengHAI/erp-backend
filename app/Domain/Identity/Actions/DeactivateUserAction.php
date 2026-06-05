<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Exceptions\SelfActionForbiddenException;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Transition: User → soft-deleted (deleted_at set).
 *
 * "Hard removal" semantic per the Phase 2A locked decision: cannot
 * log in (LoginController's $notDeleted gate rejects), recoverable
 * via Restore. The standard SoftDeletes trait on User handles the
 * deleted_at write.
 *
 * AUDIT ROW: Auditable's writeAuditOnDeleted handler dispatches to
 * action='soft_deleted' for SoftDeletes-using models (pinned in
 * Session 1's UserAuditFlowTest:LOAD-BEARING test). The
 * DeactivateUserActionAuditTest in this slice re-pins it at the
 * Action layer — a future Auditable refactor that changes the action
 * value would fail BOTH tests.
 *
 * SELF-ACTION GUARD: Phase 2A blocks self-deactivate at the API per
 * the same defense-in-depth rationale as Disable.
 */
final class DeactivateUserAction
{
    public function execute(User $target, int $actorId): User
    {
        if ($target->id === $actorId) {
            throw new SelfActionForbiddenException(actionName: 'deactivate');
        }

        return DB::transaction(function () use ($target): User {
            $target->delete(); // soft-delete via SoftDeletes trait

            // SoftDeletes sets deleted_at on the in-memory model, so
            // $target already carries the new timestamp. Calling
            // $target->fresh() would return null (default scope hides
            // soft-deleted rows); returning $target directly is what
            // every UserResource consumer needs.
            return $target;
        });
    }
}
