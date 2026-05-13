# Architecture

> The canonical, authoritative source for project architecture and decisions is **`/CLAUDE.md`** at the repo root.
> This document is a navigational summary. When the two disagree, CLAUDE.md wins.

## At a glance

- **Style:** Modular Monolith with Domain-Driven Design.
- **Backend:** Laravel 12 (API-only), PHP 8.3+, PostgreSQL 16+, Redis, Meilisearch.
- **Frontend:** Vue 3 + TypeScript + Vite + Pinia + TanStack Query + PrimeVue + Tailwind, in `/frontend`.
- **Auth:** Sanctum, with `spatie/laravel-permission` for tenant-scoped RBAC (Step 1).
- **Money:** BCMath at scale 4 — floats are banned in money code paths.

## Directory map

| Path | Responsibility |
|---|---|
| `app/Domain/<DomainName>/` | DDD core. One folder per business domain. **Never imports across domains.** |
| `app/Support/` | Framework-level cross-cutting concerns (Tenancy, Audit, Money, Workflow, ...). Domain-agnostic. |
| `app/Web/API/V1/` | Slim API controllers, form requests, resources, middleware. |
| `app/Web/Console/` | Artisan commands. |
| `app/Models/` | Framework-level only (User, Tenant). All domain models live in `app/Domain/<X>/Models/`. |
| `app/Providers/` | Service providers. `DomainEventServiceProvider` wires cross-domain listeners. |
| `database/migrations/` | Forward-only. Reviewed for tenant scoping, indexes, FKs, NOT NULL. |
| `database/seeders/Framework/` | Required seeds (roles, default rules). |
| `database/seeders/CountryTemplates/` | E.g. `CambodiaCoaSeeder`. |
| `database/seeders/Demo/` | Optional demo tenant. |
| `frontend/src/modules/` | Mirrors backend domains (accounting, hrm, ...). |
| `tests/Unit/` | Mirrors `app/`. |
| `tests/Feature/` | API endpoint tests. |
| `tests/Integration/` | Cross-domain event flow tests. |
| `docs/adr/` | Architecture Decision Records. |
| `docs/runbooks/` | Operational runbooks. |

## Boundary rules (CI-enforced)

1. `app/Domain/X/` **never** imports from `app/Domain/Y/`.
2. `app/Web/` imports from `app/Domain/`, never reverse.
3. `app/Support/` is domain-agnostic; importing `app/Domain/` from there is a code smell.

Cross-domain communication is **always** by domain event. Direct cross-domain function calls are forbidden.

## Locked decisions

See CLAUDE.md §2 (tech stack) and §4 (Accounting design). These are not up for discussion.

## What's in place (Step 1 — Tenancy, Identity, Audit)

| Concern | Where | Shipped in |
|---|---|---|
| `tenants` table + Tenant model + factory | `database/migrations/2026_05_12_120000_*`, `app/Models/Tenant.php` | slice 1 |
| `BelongsToTenant` trait + `TenantScope` + `TenantContext` (request-scoped) | `app/Support/Tenancy/` | slice 1 |
| `users.tenant_id` + `users.current_tenant_id` FK + `ResolveTenant` middleware | migration `2026_05_12_120100_*`, `app/Support/Tenancy/Middleware/ResolveTenant.php` | slice 2 |
| Sanctum cookie-SPA login (`POST /api/v1/auth/login`) | `app/Web/API/V1/Controllers/Auth/LoginController.php` + ADR 0002 | slice 3 |
| Constant-time login path + named rate limiter + dummy bcrypt hash | `LoginController`, `AppServiceProvider`, `config/auth.php` | slice 3 |
| `spatie/laravel-permission` w/ teams + `HasTenantRoles` wrapper | `config/permission.php` (teams=true), `app/Support/Tenancy/Concerns/HasTenantRoles.php` | slice 5 |
| `TenantInactiveException` (401 + `error_code=tenant_inactive`) | `app/Support/Tenancy/Exceptions/TenantInactiveException.php`, render handler in `bootstrap/app.php` | slice 5 |
| Default permissions + roles (pattern: `{domain}.{resource}.{action}`) | `database/seeders/Framework/Default*Seeder.php` | slice 5 |
| `GET /api/v1/auth/me` returning user + tenant + roles + permissions | `app/Web/API/V1/Controllers/Auth/MeController.php` | slice 5 |
| Postgres-partitioned `audit_logs` + immutability trigger | migration `2026_05_12_120300_*` | slice 6 |
| `Auditable` trait + `AuditContext` + `AuditWriter` (sync, diff-only) | `app/Support/Audit/` | slice 6 |
| `audit:partitions:rollover` artisan command + monthly schedule | `app/Support/Audit/Console/`, `routes/console.php` | slice 6 |
| `POST /api/v1/auth/logout` (outside tenant group so suspended users can still exit) | `app/Web/API/V1/Controllers/Auth/LogoutController.php` | slice 7 |
| End-to-end stack acceptance test | `tests/Integration/Step1AuthFlowTest.php` | slice 8 |

**API contract:** [`docs/api/v1/auth.md`](api/v1/auth.md) — canonical source for the frontend.
**Runbooks:** [`docs/runbooks/audit-partition-maintenance.md`](runbooks/audit-partition-maintenance.md), [`docs/runbooks/i18n-pending.md`](runbooks/i18n-pending.md).
**ADRs:** [`docs/adr/0002-cookie-based-spa-auth.md`](adr/0002-cookie-based-spa-auth.md).

## Step 1 limits — deferred work, surfaced for Step 2 planning

| Item | Status | When |
|---|---|---|
| `TenantScopedPolicy` base class | Designed, not shipped | Ships with first HRM policy (Step 2) |
| Tenant-switching endpoint (`POST /auth/switch`) | Deferred entirely | Out of Step 1; revisit if a real customer needs it |
| Self-registration / invitation flow | Deferred entirely | Future step — tenant provisioning is admin/CLI for now |
| Bearer-token auth for 3rd-party API integrations | Deferred | ~ Step 9, separate slice |
| `users.locale` column for Khmer translations | Tracked in `runbooks/i18n-pending.md` | When Khmer UI lands |
| Production cron for `php artisan schedule:run` | Required when staging exists | Deploy workflow placeholder is in CI |

## What Step 2 (HRM) inherits

Every HRM model gets these for free:

- `use BelongsToTenant` — tenant_id auto-fills from `TenantContext` on create, global scope filters reads
- `use Auditable` — created/updated/deleted/soft_deleted/restored automatically audited with diff-only JSONB before/after
- `Spatie\Permission\Traits\HasRoles` (if it's a User-shaped model) — per-tenant role scoping via the registrar team_id set by `ResolveTenant`
- Cross-tenant test pattern established (see `MeTest::isolates tenants` for the template)

HRM domain code goes in `app/Domain/HRM/` per §5.1. The first HRM endpoint should ship with a `TenantScopedPolicy` base class for the new policies — that's the just-in-time consumer of the policy machinery I deferred from Step 1.
