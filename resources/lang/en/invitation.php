<?php

declare(strict_types=1);

/**
 * Phase 2A — invitation email i18n keys (English).
 *
 * Backend email i18n lives under resources/lang/{locale}/ — separate from
 * the frontend's src/shared/locales/ which serves in-app UI rendered in
 * the browser. The split is structural: emails render server-side via
 * Mailable's __() lookups; the frontend's vue-i18n is unreachable from
 * backend code.
 *
 * Future Khmer migration: add resources/lang/km/invitation.php with the
 * same key shape. Per-user locale routing lands when User gains a locale
 * column (see User model @todo). Until then, every email renders English.
 *
 * Sets the convention for all future emails: backend lang for emails,
 * frontend lang for UI.
 */
return [
    'email' => [
        'subject' => "You've been invited to join :tenant on MyERP",
        'greeting_named' => 'Hi :name,',
        'greeting_anonymous' => 'Hi there,',
        'intro' => ':inviter has invited you to join :tenant as a :role on MyERP.',
        'cta_intro' => 'Click the button below to accept the invitation and set up your account:',
        'cta_button' => 'Accept invitation',
        'expiry' => 'This invitation expires on :date.',
        'safe_to_ignore' => "If you didn't expect this invitation, you can safely ignore this email.",
        'signature' => 'MyERP Team',
    ],
];
