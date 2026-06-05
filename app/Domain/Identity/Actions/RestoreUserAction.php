<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Transition: soft-deleted User → not-deleted (deleted_at = null).
 *
 * NO self-action guard for the same reason as EnableUserAction —
 * a soft-deleted user can't log in to call this endpoint, so
 * self-restore is structurally unreachable.
 *
 * Audit row: Auditable's writeAuditOnUpdated handles the special-case
 * of deleted_at going from non-null → null and writes action='restored'
 * (verified in app/Support/Audit/Concerns/Auditable.php). No special
 * handling needed.
 */
final class RestoreUserAction
{
    public function execute(User $target): User
    {
        return DB::transaction(function () use ($target): User {
            $target->restore();

            return $target->refresh();
        });
    }
}
