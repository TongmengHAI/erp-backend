<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Exceptions\SelfActionForbiddenException;
use App\Models\User;
use App\Support\Identity\Enums\UserStatus;
use Illuminate\Support\Facades\DB;

/**
 * Transition: User status='active' → 'inactive'.
 *
 * "Soft-block" semantic per the Phase 2A locked decision: cannot log
 * in (LoginController's $statusOk gate rejects), reversible via Enable.
 * NO deleted_at change — that's Deactivate.
 *
 * SELF-ACTION GUARD: Phase 2A blocks self-disable at the API even
 * when the UI hides the button (defense-in-depth). actorId is passed
 * explicitly rather than read from Auth::id() so the Action is
 * unit-testable without auth mocking, and so the controller layer is
 * the auth boundary (mirrors HRM Approve/Reject pattern).
 *
 * Audit row fires via Auditable's 'updated' event (status field
 * delta). No special handling needed.
 */
final class DisableUserAction
{
    public function execute(User $target, int $actorId): User
    {
        if ($target->id === $actorId) {
            throw new SelfActionForbiddenException(actionName: 'disable');
        }

        return DB::transaction(function () use ($target): User {
            $target->status = UserStatus::Inactive;
            $target->save();

            return $target->refresh();
        });
    }
}
