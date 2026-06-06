<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Thrown by DeleteRoleAction when invoked on a custom role that has
 * users currently assigned. Per Phase 2B Q6, no fallback / orphan
 * logic — the admin must manually reassign the affected users before
 * deleting the role.
 *
 * SELF-RENDERING — 422 with TWO load-bearing fields in the body:
 * error_code AND users_count. The frontend's RoleDeleteConfirm
 * component reads users_count to render the actionable message
 * ("Cannot delete — N users are currently assigned to this role.
 * Reassign them first.") — without the count, the message is
 * unactionable. Both fields are part of the contract; feature tests
 * assert them explicitly.
 *
 * Response shape:
 *
 *   {
 *     "message": "Cannot delete a role with users currently assigned.",
 *     "error_code": "role_in_use",
 *     "users_count": 3
 *   }
 *
 * users_count is the EXACT count of model_has_roles rows pointing at
 * the role being deleted — not a sampled or estimated value.
 */
final class RoleInUseException extends RuntimeException
{
    public function __construct(
        public readonly int $usersCount,
        string $message = 'Cannot delete a role with users currently assigned.',
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
            'error_code' => 'role_in_use',
            'users_count' => $this->usersCount,
        ], 422);
    }
}
