<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Thrown by Disable / Deactivate actions when the actor and the target
 * are the same user. Phase 2A locked decision: self-disable +
 * self-deactivate are blocked at the API layer (defense-in-depth) even
 * when the UI hides those buttons on the current user's own row.
 *
 * SELF-RENDERING — same pattern as InvalidLeaveRequestTransitionException
 * (HRM). HTTP 403 with a stable `error_code='self_action_forbidden'`
 * so the SPA can decide whether to swallow silently (it already
 * shouldn't have offered the action) or surface a toast.
 */
final class SelfActionForbiddenException extends RuntimeException
{
    public function __construct(
        public readonly string $actionName,
        string $message = 'You cannot perform this action on your own account.',
    ) {
        parent::__construct($message);
    }

    public function render(Request $request): ?JsonResponse
    {
        if (! $request->expectsJson()) {
            return null;
        }

        return new JsonResponse([
            'message' => $this->getMessage(),
            'error_code' => 'self_action_forbidden',
            'action' => $this->actionName,
        ], 403);
    }
}
