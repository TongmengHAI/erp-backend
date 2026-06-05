<?php

declare(strict_types=1);

namespace App\Web\API\V1\Controllers\Public;

use App\Domain\Identity\Actions\AcceptInvitationAction;
use App\Web\API\V1\Controllers\Controller;
use App\Web\API\V1\Requests\Public\AcceptInvitationRequest;
use App\Web\API\V1\Resources\TenantResource;
use App\Web\API\V1\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * POST /api/v1/invitations/{token}/accept.
 *
 * Public endpoint — no auth required to call it. After successful
 * acceptance, the response auto-logs the new user in (per Q4 — auto-
 * login after signup) by issuing a Sanctum session via Auth::login.
 *
 * Response shape matches the LoginController's: { data: { user,
 * tenant } } so the SPA's useAuthStore can consume either response
 * uniformly.
 *
 * Errors are 422 with stable error_codes (token_invalid / expired /
 * cancelled / accepted) routed through InvalidInvitationException's
 * self-render. The SPA route guard for /invitation/:token branches on
 * error_code to render the appropriate InvitationInvalidPage variant.
 */
final class AcceptInvitationController extends Controller
{
    public function __invoke(
        AcceptInvitationRequest $request,
        string $token,
        AcceptInvitationAction $action,
    ): JsonResponse {
        /** @var array{password: string, name?: string|null} $data */
        $data = $request->validated();

        $result = $action->execute(
            rawToken: $token,
            password: $data['password'],
            name: $data['name'] ?? null,
        );

        // Auto-login per Q4 — matches the LoginController flow exactly,
        // including session regeneration to defeat session-fixation
        // around the moment of credential setup.
        Auth::guard('web')->login($result->user, remember: false);
        $request->session()->regenerate();

        return response()->json([
            'data' => [
                'user' => new UserResource($result->user),
                'tenant' => $result->user->tenant !== null
                    ? new TenantResource($result->user->tenant)
                    : null,
            ],
        ], 201);
    }
}
