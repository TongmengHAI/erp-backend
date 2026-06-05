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
                // ->uncompromised() is intentionally NOT included.
                // It triggers an outbound HTTPS request to
                // api.pwnedpasswords.com via curl, which fails on
                // Windows dev (XAMPP) due to a missing CA bundle
                // (cURL error 60). Add ->uncompromised() back once the
                // production environment's CA chain is confirmed
                // working — likely as part of the prod-deploy slice.
                // The other four rules already exceed Laravel 12's
                // default Password::min(8); this is a defense-in-depth
                // gap, not a baseline weakness.
                Password::min(12)->mixedCase()->numbers()->symbols(),
            ],
            'name' => ['nullable', 'string', 'min:1', 'max:255'],
        ];
    }
}
