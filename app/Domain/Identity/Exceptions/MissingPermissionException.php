<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Thrown when a request is well-formed (passes FormRequest validation)
 * but contains a field whose presence requires a specific permission
 * the actor lacks.
 *
 * Distinct from a blanket 403 — the actor IS allowed on the endpoint
 * (e.g. PATCH /admin/users/{id}); they just can't perform THIS specific
 * field-level change. The response shape names the missing permission
 * so the SPA can render an actionable explanation ("Role assignment
 * requires the Manage roles permission.") rather than a generic
 * forbidden message.
 *
 * SELF-RENDERING — 403 with error_code='missing_permission' +
 * required_permission. Both fields are part of the contract; the
 * Phase 2B roles.assign granularity test asserts them explicitly.
 *
 * Canonical instance: PATCH /admin/users/{id} with role_id in the body
 * but actor lacks roles.assign. Future fields with their own
 * permission gate route through this same exception.
 */
final class MissingPermissionException extends RuntimeException
{
    public function __construct(
        public readonly string $requiredPermission,
        string $message = 'You do not have permission to perform this action.',
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
            'error_code' => 'missing_permission',
            'required_permission' => $this->requiredPermission,
        ], 403);
    }
}
