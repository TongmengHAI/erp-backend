<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Invitation Token Lifetime (Days)
    |--------------------------------------------------------------------------
    |
    | Per Phase 2A locked decision: 7 days. Configurable via env so the
    | value can be tuned without a code change. No UI exposure in Phase 2A;
    | a future Settings page may surface it.
    */
    'invitation_lifetime_days' => env('INVITATION_TOKEN_LIFETIME_DAYS', 7),
];
