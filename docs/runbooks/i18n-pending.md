# i18n — Pending work (tracked, not yet implemented)

The system today is **English-only** by deliberate choice. Khmer (`km`) UI translations are a future workstream. This file tracks what's planned so the decision isn't lost.

## Current state (slice 5)

- `tenants.country_code` = `'KH'` by default. Used for business context (regulatory, NBC FX rates, fiscal calendars) — **not for language selection**.
- `tenants.timezone` = `'Asia/Phnom_Penh'` by default. Date formatting on the frontend respects this.
- `tenants.functional_currency` = `'USD'` or `'KHR'` per tenant. Money formatting goes through `Intl.NumberFormat('en', { currency: tenant.functional_currency })`.
- `users.locale` — **does not exist as a column**. All UI is rendered in English.

## When Khmer translations start

Migration:

```php
Schema::table('users', function (Blueprint $table): void {
    $table->string('locale', 8)->default('en')->after('email');
});
```

- Allowed values: `'en'`, `'km'`. CHECK constraint enforced at DB level.
- Expose `locale` in `UserResource` and the `/api/v1/auth/me` payload.
- Frontend reads `me.user.locale` and feeds it to its i18n framework (vue-i18n) and to date/number formatting via `Intl`.
- Translations live in `frontend/src/locales/{en,km}.json` — keys mirror domain structure.

## What stays unchanged

- Money formatting always uses `'en'` for `Intl.NumberFormat` even when the user locale is `'km'` — Khmer locale puts the currency symbol in a position that confuses bookkeepers; we'll always render USD/KHR amounts in Western format.
- Backend error messages and API responses stay English. Translations are a frontend concern.
- `tenants.timezone` and `tenants.functional_currency` are unrelated to `users.locale` and don't change.

## Why not now

- Backend can't usefully translate anything until there's a frontend consuming locale.
- The Vue scaffold doesn't have vue-i18n installed (skipped from Step 0 scope per "don't add deps without asking").
- No translation source-of-truth tooling yet (probably Lokalise or just JSON files in-repo). That decision is its own design exercise.
- We don't want to add `users.locale` and have it sit unused for months — adds noise to /me payloads and migrations.

Pick this up when there's actual demand from a tenant or before Cambodia-region launch, whichever comes first.
