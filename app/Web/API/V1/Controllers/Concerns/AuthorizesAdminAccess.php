<?php

declare(strict_types=1);

namespace App\Web\API\V1\Controllers\Concerns;

use Illuminate\Http\Request;

/**
 * Admin-area authorization chokepoint. Mirror of AuthorizesHrmAccess
 * for the admin app's controllers (settings.*, future Users/Roles/etc.).
 *
 * Same chokepoint discipline: route every permission check through
 * `authorizeAdmin()` so Spatie's team-scope wiring stays a single-flip
 * concern for future per-company permission scoping (the same H1c
 * refactor concern AuthorizesHrmAccess documents).
 *
 * The implementation is identical to AuthorizesHrmAccess — separate
 * traits exist so a future audit grep on "admin endpoints" lands on
 * an admin-scoped trait, not a misleadingly-named HRM one. If a third
 * area starts duplicating this, extract a generic `AuthorizesViaSpatie`
 * and have the three traits delegate to it.
 */
trait AuthorizesAdminAccess
{
    protected function authorizeAdmin(Request $request, string $permission): void
    {
        $user = $request->user();
        if ($user === null || ! $user->can($permission)) {
            abort(403);
        }
    }
}
