<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Thrown by Update / Delete Role Actions when invoked on a system role
 * (is_system=true). Per Phase 2B locked decision Q15, system roles
 * (tenant_admin / accountant / viewer) are immutable: cannot be
 * renamed, have their permissions modified, or be deleted through the
 * UI or API.
 *
 * SELF-RENDERING — same pattern as SelfActionForbiddenException and
 * InvalidLeaveRequestTransitionException. HTTP 403 with a stable
 * error_code so the SPA can branch.
 *
 * Response shape:
 *
 *   {
 *     "message": "System roles cannot be modified.",
 *     "error_code": "system_role_immutable",
 *     "action": "update" | "delete"
 *   }
 *
 * Defense-in-depth: this is the APPLICATION layer enforcement. The DB
 * doesn't enforce immutability of system rows directly — a raw SQL
 * UPDATE could still mutate them. Per the Plan's Q15 discussion, the
 * two-layer defense (FormRequest + Action) is sufficient for v1 SaaS;
 * trigger-based DB enforcement is available if production posture
 * later demands it.
 */
final class RoleImmutableException extends RuntimeException
{
    public function __construct(
        public readonly string $actionName,
        string $message = 'System roles cannot be modified.',
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
            'error_code' => 'system_role_immutable',
            'action' => $this->actionName,
        ], 403);
    }
}
