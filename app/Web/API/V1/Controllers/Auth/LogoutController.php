<?php

declare(strict_types=1);

namespace App\Web\API\V1\Controllers\Auth;

use App\Web\API\V1\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * POST /api/v1/auth/logout — symmetric to LoginController.
 *
 * - Auth::guard('web')->logout() clears the authenticated user from the guard.
 * - session->invalidate() destroys the session data + regenerates the session ID.
 * - session->regenerateToken() rolls a fresh CSRF token for the next anonymous session.
 *
 * Returns 204 No Content. Cookie still arrives on the response with the new
 * session ID (Laravel handles); the OLD session ID is invalidated server-side.
 *
 * Lives OUTSIDE the `tenant` middleware (auth:sanctum only). A user whose
 * current tenant is suspended must still be able to log out — otherwise the
 * tenant_inactive 401 from ResolveTenant would block this endpoint and trap
 * the user in a state with no exit.
 */
final class LogoutController extends Controller
{
    public function __invoke(Request $request): Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }
}
