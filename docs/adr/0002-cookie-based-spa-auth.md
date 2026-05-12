# ADR 0002 — Cookie-based Sanctum SPA auth for first-party login

**Status:** Accepted
**Date:** 2026-05-12
**Step / slice:** Step 1, Slice 3

## Context

Step 1 needs an authentication entry point for the Vue SPA against the Laravel API. Sanctum supports two distinct modes:

- **Stateful SPA** — `Auth::login()` creates a Laravel session; an HttpOnly cookie carries authentication. Same-origin SPA requests proxied through `/api/*` are recognised as "stateful" via `EnsureFrontendRequestsAreStateful` and pick up the session automatically.
- **API tokens** — `$user->createToken(...)` issues a Bearer token persisted to `personal_access_tokens`; clients send it in the `Authorization` header. Configurable TTL, refresh requires an explicit endpoint.

The first-party UI is the Vue SPA at `localhost:5173` (dev) / single-origin in production. There are no third-party API consumers in scope for Step 1.

## Decision

Slice 3 implements **cookie-based Sanctum SPA auth** for first-party login. Specifically:

- `POST /api/v1/auth/login` calls `Auth::guard('web')->login($user)` and regenerates the session.
- `EnsureFrontendRequestsAreStateful` is enabled on the api middleware group via `$middleware->statefulApi()` in `bootstrap/app.php`.
- `SANCTUM_STATEFUL_DOMAINS` enumerates the origins eligible for stateful auth.
- Session lifetime is governed by `SESSION_LIFETIME` (set to 480 minutes — a working ERP day).
- No Bearer token is issued. No `personal_access_tokens` row is created on login.

## Consequences

**Pros:**

- HttpOnly + SameSite=Lax cookies are not exfiltrated by XSS that compromises JS context. (Bearer tokens stored in localStorage are.)
- CSRF protection comes free via Sanctum's stateful guard.
- No client-side token storage / refresh logic in the SPA; no token TTL gymnastics.
- One fewer table to monitor in production (no `personal_access_tokens` churn).
- Existing `.env.example` already configured for this mode (`SANCTUM_STATEFUL_DOMAINS`).

**Cons:**

- Cookie auth requires same-site or explicit CORS+credentials configuration. Already handled because the Vue dev server proxies `/api` to Laravel (single origin from the browser's view).
- Logout (slice 5+ scope) must use `Auth::logout()` + `$request->session()->invalidate()`, not `$token->delete()`.

## Future considerations — Bearer tokens for third-party API integrations

**Tracked for a later step (post Step 1, likely Step 9 or a standalone slice).**

Third-party integrations (tenant admins issuing API keys for partner systems, CI tooling, mobile clients if/when added) will need Bearer-token auth as a *separate* concern. Sketch:

- New endpoint surface, e.g. `POST /api/v1/tokens` (tenant-admin only).
- Sanctum **abilities** carry scoped permissions on the token — `['accounting.read', 'inventory.write', …]` — independent of the user's Spatie roles. Spatie governs human users; abilities govern API tokens.
- Explicit TTL (e.g. 1 year for partner keys with manual revocation).
- UI for tenant admins to list / rotate / revoke their tenant's API keys.
- Logout-equivalent endpoint: `DELETE /api/v1/tokens/{id}`.

**This is explicitly out of scope for any slice in Step 1.** The decision to ship cookie-SPA first does not preclude Bearer tokens — the two modes coexist on Sanctum without conflict. When the Bearer-token slice lands, it will add a parallel path through the auth stack without touching the cookie login flow.
