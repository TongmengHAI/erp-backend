# Backend — Laravel API

This directory is the **backend service** of the ERP. The canonical project context lives at [`../CLAUDE.md`](../CLAUDE.md) — read it in full before responding to any request here. It captures the locked tech stack, architectural rules, agent rules, and build order for the whole ERP.

This file is just a breadcrumb.

## Quick refs (mirror of the root CLAUDE.md)

- **Stack:** Laravel 12 (API-only), PHP 8.3+, PostgreSQL 16+, Redis, Meilisearch
- **Auth:** Laravel Sanctum + `spatie/laravel-permission` (tenant-scoped, Step 1)
- **Money:** BCMath scale 4 — floats are banned in money code paths
- **Layout:**
  - `app/Domain/<X>/` — DDD domains (Accounting, HRM, Inventory, Procurement, Sales)
  - `app/Web/API/V1/` — slim controllers, form requests, resources, middleware
  - `app/Support/` — cross-cutting (Tenancy, Audit, Money, Workflow, …)
- **Boundary rule:** `app/Domain/X/` **never** imports from `app/Domain/Y/`. Cross-domain comms is by event only.
- **Build order:** see root CLAUDE.md §6.

See [`docs/architecture.md`](./docs/architecture.md) for the directory map.
