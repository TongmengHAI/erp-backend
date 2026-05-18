# Auth API (v1)

> Source of truth for the frontend per §5.0. Endpoints below are the contract the SPA consumes; frontend TypeScript types in `frontend/src/modules/auth/types/` derive from this file. Don't change endpoint shapes without updating both sides.

## Endpoints

### `POST /api/v1/auth/login`

Authenticate a user with email + password. Issues a stateful Sanctum session cookie (HttpOnly, SameSite=Lax) — no Bearer token. See [ADR 0002](../../adr/0002-cookie-based-spa-auth.md) for the cookie-vs-Bearer decision.

**Request**

```http
POST /api/v1/auth/login HTTP/1.1
Origin: https://app.example.com
Content-Type: application/json
X-XSRF-TOKEN: <value from sanctum/csrf-cookie>

{
  "email": "jane@acme.example",
  "password": "•••••••••"
}
```

| Header | Required | Notes |
|---|---|---|
| `Origin` | yes | Must match one of `SANCTUM_STATEFUL_DOMAINS` |
| `Content-Type` | yes | `application/json` |
| `X-XSRF-TOKEN` | yes | Read from the `XSRF-TOKEN` cookie set by `GET /sanctum/csrf-cookie` |
| `Accept` | recommended | `application/json` |

**Response — 200 OK** (session cookie set in `Set-Cookie`)

```json
{
  "data": {
    "user": {
      "id": 42,
      "name": "Jane Bookkeeper",
      "email": "jane@acme.example",
      "email_verified_at": "2026-05-12T08:00:00+00:00"
    },
    "tenant": {
      "id": 7,
      "slug": "acme",
      "name": "Acme Trading Co.",
      "country_code": "KH",
      "default_currency": "USD",
      "functional_currency": "USD",
      "timezone": "Asia/Phnom_Penh"
    }
  }
}
```

Login response **does not** include roles/permissions. Use `GET /auth/me` immediately after login to fetch them.

**Errors**

| Status | Body | Trigger |
|---|---|---|
| 401 | `{ "message": "These credentials do not match our records.", "errors": { "email": ["..."] } }` | Wrong password, user not found, or tenant suspended/archived. Same body for all three — no information disclosure. |
| 422 | `{ "message": "...", "errors": { "email": ["..."], "password": ["..."] } }` | Missing/malformed input |
| 429 | `{ "message": "Too Many Attempts." }` (with `Retry-After` header) | More than 5 attempts/minute per (IP, email) tuple |

---

### `GET /api/v1/auth/me`

Return the authenticated user's current auth context — user, current tenant, role names, and the flat permission list. Used by the SPA on app boot, route navigation, and refocus to keep auth state and `can()` checks accurate.

**Request**

```http
GET /api/v1/auth/me HTTP/1.1
Origin: https://app.example.com
Cookie: <session cookie set by /auth/login>
Accept: application/json
```

No request body. No query parameters.

**Response — 200 OK**

```json
{
  "data": {
    "user": {
      "id": 42,
      "name": "Jane Bookkeeper",
      "email": "jane@acme.example",
      "email_verified_at": "2026-05-12T08:00:00+00:00"
    },
    "tenant": {
      "id": 7,
      "slug": "acme",
      "name": "Acme Trading Co.",
      "country_code": "KH",
      "default_currency": "USD",
      "functional_currency": "USD",
      "timezone": "Asia/Phnom_Penh"
    },
    "current_company": {
      "id": 3,
      "slug": "acme-trading",
      "name": "Acme Trading Co.",
      "country_code": "KH",
      "default_currency": "USD",
      "functional_currency": "USD",
      "timezone": "Asia/Phnom_Penh",
      "status": "active"
    },
    "companies": [
      { "id": 3, "slug": "acme-trading", "name": "Acme Trading Co.", "status": "active" },
      { "id": 4, "slug": "acme-retail",  "name": "Acme Retail",      "status": "active" }
    ],
    "roles": ["accountant"],
    "permissions": [
      "accounting.journal_entry.view",
      "accounting.journal_entry.create"
    ]
  }
}
```

| Field | Type | Notes |
|---|---|---|
| `data.user.id` | int | Stable user identifier |
| `data.user.email` | string | Globally unique, lowercased |
| `data.user.email_verified_at` | ISO 8601 string \| null | |
| `data.tenant.id` | int | Stable tenant identifier |
| `data.tenant.slug` | string | URL-safe short identifier (≤63 chars) |
| `data.tenant.country_code` | string (ISO 3166-1 alpha-2) | Business context — used for fiscal calendars, NBC FX rates, etc. Not for UI language. |
| `data.tenant.default_currency` | string (ISO 4217) | Display currency the tenant prefers |
| `data.tenant.functional_currency` | string (ISO 4217) | The tenant's books currency — used for the accounting engine. Always use this for monetary `Intl.NumberFormat`. |
| `data.tenant.timezone` | IANA timezone string | Used for date formatting on the frontend |
| `data.current_company` | Company object \| null | The resolved company for this request, full shape. `null` when no company resolved — frontend renders a picker (see "Company context" section below). Fields mirror `data.tenant` plus `status`. |
| `data.companies` | array of company-brief objects | Every active company in the user's tenant. Brief shape: `{ id, slug, name, status }`. Sufficient to render a switcher; full shape via the API only for `current_company`. Sorted by name. |
| `data.roles` | array of strings | Role names assigned to the user in the **current** tenant. Display-only. Frontend MUST NOT branch on role names — only on `permissions`. Per-company role variance is intended (a user can be `accountant` in one company and `viewer` in another within the same tenant); the per-company permission split lands in a future slice. For now, `permissions` is tenant-aggregated. |
| `data.permissions` | array of strings | Flat list of permission names the user effectively holds in the current tenant (direct grants ∪ permissions via assigned roles). Used by `useAuthStore().can('...')`. Currently tenant-scoped; will gain a company dimension in a future slice. |

## Company context

The SPA selects a company via the `X-Company-Id` request header on every authenticated request. The header carries the company's numeric ID (`X-Company-Id: 7`). The server resolves company context through a 5-branch chain:

1. **`X-Company-Id` header** — explicit selection. On success, server persists the choice as `user.current_company_id`. On invalid ID (no such company, wrong tenant, archived): **403** with `"Company access denied."`.
2. **`user.current_company_id`** — last-used company; survives sessions.
3. **`user.default_company_id`** — preferred home; promoted to current on hit.
4. **Sole-company fallback** — if the tenant has exactly one active company, pin it. Single-company tenants work without any per-user config; default and current are backfilled on first access.
5. **None matched** — route decides:
   - Routes marked `company:optional` (currently only `/auth/me`) proceed with `current_company: null` so the SPA can render a picker.
   - All other authenticated routes return **401** with `error_code: 'company_required'` + `available_companies` array (brief-shape companies in the user's tenant).

Transition from single-company to multi-company: when a tenant provisions a second company, the server atomically backfills every existing user's `default_company_id` and `current_company_id` to the previously-sole company. Existing user sessions continue without interruption; switching to the new company is an explicit `X-Company-Id` action. (See CLAUDE.md §3 "Multi-company within a tenant" for the architectural rule.)

## Error response shape for `company_required`

```json
{
  "message": "No company context resolved.",
  "error_code": "company_required",
  "available_companies": [
    { "id": 3, "slug": "acme-trading", "name": "Acme Trading Co.", "status": "active" },
    { "id": 4, "slug": "acme-retail",  "name": "Acme Retail",      "status": "active" }
  ]
}
```

Returned on **401**. The SPA should route to a company-picker UI, prompt the user to choose, then re-issue the original request with `X-Company-Id: <chosen-id>`. Distinguished from `tenant_inactive` (different `error_code`) and from a plain 401 (which means the session is gone — route to `/login`).

**Errors**

| Status | Body | Trigger |
|---|---|---|
| 401 | `{ "message": "Unauthenticated." }` | No session cookie, expired cookie, or invalid cookie |
| 401 | `{ "message": "Tenant <slug> is suspended.", "error_code": "tenant_inactive" }` | User authenticated, but their current tenant is `suspended` or `archived`. Frontend should route to a "tenant suspended" screen rather than `/login`. |
| 403 | `{ "message": "..." }` | (Reserved.) Reached only if the user's `tenant_id` and `current_tenant_id` are both null, or the resolved tenant has been hard-deleted. Indicates orphaned state — typically signals a bug or admin error. |
| 429 | `{ "message": "Too Many Attempts." }` | More than 60 requests/minute (IP-keyed). |

---

### `POST /api/v1/auth/logout`

Destroy the authenticated session and roll a fresh CSRF token. Returns 204 No Content.

**Lives outside the tenant-required group.** A user whose current tenant is suspended must still be able to log out — otherwise they'd be trapped in a `tenant_inactive` 401 loop.

**Request**

```http
POST /api/v1/auth/logout HTTP/1.1
Origin: https://app.example.com
Cookie: <session cookie set by /auth/login>
X-XSRF-TOKEN: <value from XSRF-TOKEN cookie>
Accept: application/json
```

No request body.

**Response — 204 No Content** (with `Set-Cookie` clearing/rotating the session cookie)

**Errors**

| Status | Body | Trigger |
|---|---|---|
| 401 | `{ "message": "Unauthenticated." }` | No session cookie, expired cookie, or invalid cookie |
| 429 | `{ "message": "Too Many Attempts." }` | More than 30 requests/minute (IP-keyed). |

After logout, subsequent calls to `GET /api/v1/auth/me` return 401 (no `error_code`) — the SPA should clear local `useAuthStore` state and route to `/login`.

---

## Error code reference

Stable `error_code` values returned in JSON error bodies. Frontend uses these to drive routing decisions; messages may change, codes are stable.

| Code | Status | Meaning | Frontend behaviour |
|---|---|---|---|
| `tenant_inactive` | 401 | Current tenant is suspended or archived. | Route to a "tenant suspended" screen; do NOT redirect to `/login`. |

(More codes will be appended by future slices.)

---

## Permission name pattern

All permission names follow `{domain}.{resource}.{action}`. See [`DefaultPermissionsSeeder`](../../../database/seeders/Framework/DefaultPermissionsSeeder.php) for the canonical pattern definition and the catalog.

**Current catalog (slice 5 — minimum to make /me observable):**

- `tenant.settings.manage`
- `accounting.journal_entry.view`
- `accounting.journal_entry.create`

---

## Notes for frontend implementers

- Call `GET /sanctum/csrf-cookie` once per session before `POST /auth/login` to obtain the XSRF token.
- After login, call `GET /auth/me` to populate `useAuthStore`. Refetch on route navigation and tab refocus to keep permissions current.
- On 401 from `/me`:
  - Without `error_code` → session expired, route to `/login`.
  - With `error_code: tenant_inactive` → route to a tenant-suspended screen.
- The `permissions` array is the **only** thing to drive UI gating. `roles` is for display badges only (e.g. "Logged in as Accountant").
