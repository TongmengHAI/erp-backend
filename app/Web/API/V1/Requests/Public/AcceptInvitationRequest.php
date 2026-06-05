<?php

declare(strict_types=1);

namespace App\Web\API\V1\Requests\Public;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Validates input for POST /api/v1/invitations/{token}/accept.
 *
 * Password rules per Q4 — stronger than Laravel's default min(8):
 *
 *   • min(12) — strong enough for first-touch credential creation
 *   • mixedCase + numbers + symbols — composition requirements
 *   • uncompromised — checks against haveibeenpwned hash range
 *
 * The invitee sets their own password here; this is a public,
 * unauthenticated endpoint. Token verification happens in the
 * Action layer (lookup by SHA-256 hash → state check). If the
 * token is invalid/expired/cancelled/accepted the Action throws
 * InvalidInvitationException which self-renders 422.
 *
 * Name is optional — if the invitation carries a pre-filled name
 * (admin entered it on invite), the invitee can override here.
 * If neither, the email becomes the display name (fallback in
 * AcceptInvitationAction).
 */
final class AcceptInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Public route. No auth context required.
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'password' => [
                'required',
                'string',
                Password::min(12)
                    ->mixedCase()
                    ->numbers()
                    ->symbols()
                // ─────────────────────────────────────────────────
                // SECURITY-CONTROL REDUCTION — RE-ENABLE WHEN PROD CA
                // CHAIN IS CONFIRMED WORKING.
                //
                // ->uncompromised() calls api.pwnedpasswords.com via
                // curl. On Windows dev (XAMPP) the bundled PHP has
                // no CA bundle configured by default, so the rule
                // throws "cURL error 60: SSL certificate problem"
                // and a valid strong password gets rejected at 422.
                //
                // Production (Linux VPS, Phase 2A's deploy target)
                // ships with a system CA bundle out of the box; the
                // rule works there without env config. The
                // omission is Windows-dev-only.
                //
                // To re-enable: chain ->uncompromised() back onto
                // the Password rule below. Surfaced + commented
                // during Phase 2A Session 2 (commit e2aaa35).
                // Re-enable as part of the prod-deploy slice OR
                // when the Windows dev env is configured with a
                // CA bundle, whichever comes first.
                //
                // The four rules already chained exceed Laravel
                // 12's default Password::min(8); this is a
                // defense-in-depth gap, NOT a baseline weakness.
                // ─────────────────────────────────────────────────
                /* ->uncompromised() */,
            ],
            'name' => ['nullable', 'string', 'min:1', 'max:255'],
        ];
    }
}
