<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Models\User;
use App\Support\Identity\Enums\UserStatus;
use Illuminate\Support\Facades\DB;

/**
 * Transition: User status='inactive' → 'active'.
 *
 * NO self-action guard — self-enable is structurally impossible (a
 * disabled user can't log in to call this endpoint), and a future
 * scenario where the user somehow IS active and re-enables themselves
 * is harmless.
 *
 * Idempotent: enabling an already-active user is a no-op that still
 * writes an audit row (the Auditable trait skips no-op updates, so the
 * audit row only lands when status actually changed). This is the
 * intentional behavior — re-enabling is safe to retry.
 */
final class EnableUserAction
{
    public function execute(User $target): User
    {
        return DB::transaction(function () use ($target): User {
            $target->status = UserStatus::Active;
            $target->save();

            return $target->refresh();
        });
    }
}
