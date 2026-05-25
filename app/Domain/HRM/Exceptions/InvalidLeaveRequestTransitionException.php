<?php

declare(strict_types=1);

namespace App\Domain\HRM\Exceptions;

use App\Domain\HRM\Enums\LeaveRequestStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Thrown by Approve / Reject / Update Leave-Request Actions when the
 * request's current state does not permit the attempted transition.
 *
 * The Approve/Reject Actions throw this when invoked on a non-pending
 * row. The Update Action throws this when invoked on a non-pending row
 * (terminal states are read-only except for soft-delete).
 *
 * SELF-RENDERING — Laravel's exception handler calls render() on the
 * exception itself before falling back to the global handler. This
 * keeps the HTTP mapping next to the exception definition (one file
 * to read) and the controllers stay clean: they don't need a
 * try/catch around the Action call to translate the exception into
 * a response.
 *
 * Response shape (422 with stable error_code + from/to for the SPA):
 *
 *   {
 *     "message": "Cannot approve a request that is already approved.",
 *     "error_code": "invalid_transition",
 *     "from": "approved",
 *     "to":   "approved"
 *   }
 *
 * The from/to fields are part of the contract — feature tests assert
 * them explicitly (assertJsonPath('from', '...') and ('to', '...'))
 * so a silent contract change at the controller layer surfaces as a
 * test failure rather than a silent SPA regression.
 */
final class InvalidLeaveRequestTransitionException extends RuntimeException
{
    public function __construct(
        public readonly LeaveRequestStatus $from,
        public readonly LeaveRequestStatus $to,
        string $message,
    ) {
        parent::__construct($message);
    }

    public function render(Request $request): ?JsonResponse
    {
        if (! $request->expectsJson()) {
            // Non-JSON requests fall back to the framework's default
            // handler. The SPA never hits this path; this guard exists
            // so a future browser-rendered admin view (if one lands)
            // doesn't get JSON when it expected HTML.
            return null;
        }

        return new JsonResponse([
            'message' => $this->getMessage(),
            'error_code' => 'invalid_transition',
            'from' => $this->from->value,
            'to' => $this->to->value,
        ], 422);
    }
}
