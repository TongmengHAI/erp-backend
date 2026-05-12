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
