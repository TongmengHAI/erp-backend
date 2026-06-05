<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Thrown by AcceptInvitationAction / ShowInvitationController when the
 * raw token in the URL doesn't resolve to a usable invitation. Four
 * mutually-exclusive failure modes, each with a stable error_code:
 *
 *   token_invalid — hashed token has no matching row (typo, forged,
 *                   from an obsoleted re-send: the soft-deleted prior
 *                   invitation's token doesn't resolve either).
 *   expired       — row exists but expires_at < now.
 *   cancelled     — row exists and cancelled_at IS NOT NULL.
 *   accepted      — row exists and accepted_at IS NOT NULL.
 *
 * SELF-RENDERING — same pattern as InvalidLeaveRequestTransitionException
 * + SelfActionForbiddenException. HTTP 422 with error_code; the SPA
 * route guard for /invitation/:token branches on error_code to render
 * the appropriate InvitationInvalidPage variant (deferred to Session 5).
 *
 * 422, not 404 — even the token_invalid case stays 422 so the SPA can
 * surface a user-friendly "this invitation link is not valid" page
 * rather than the generic 404 catch-all. The deliberate non-404 is
 * also a small privacy nudge: a 404 on a guess would leak existence;
 * 422 with the same body for both invalid-token and other states
 * keeps the response shape uniform.
 */
final class InvalidInvitationException extends RuntimeException
{
    public const CODE_TOKEN_INVALID = 'token_invalid';

    public const CODE_EXPIRED = 'expired';

    public const CODE_CANCELLED = 'cancelled';

    public const CODE_ACCEPTED = 'accepted';

    public function __construct(
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function tokenInvalid(): self
    {
        return new self(
            self::CODE_TOKEN_INVALID,
            'This invitation link is not valid.',
        );
    }

    public static function expired(): self
    {
        return new self(
            self::CODE_EXPIRED,
            'This invitation has expired. Ask the inviter to send a new one.',
        );
    }

    public static function cancelled(): self
    {
        return new self(
            self::CODE_CANCELLED,
            'This invitation has been cancelled by the administrator.',
        );
    }

    public static function accepted(): self
    {
        return new self(
            self::CODE_ACCEPTED,
            'This invitation has already been used. Sign in instead.',
        );
    }

    public function render(Request $request): ?JsonResponse
    {
        if (! $request->expectsJson()) {
            return null;
        }

        return new JsonResponse([
            'message' => $this->getMessage(),
            'error_code' => $this->errorCode,
        ], 422);
    }
}
