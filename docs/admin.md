# Admin area

The admin app holds per-company configuration and (in future stages) Users, Roles, Module Entitlement, and Branch Admin scoping. v1 ships **HRM Settings** as the single concrete admin page; the layout + permission convention + URL space ship now so subsequent admin features slot in mechanically.

## Permission namespace

`settings.*` is a **top-level** permission domain, distinct from the `{domain}.{resource}.{action}` business permissions (`hrm.employee.view`, `accounting.journal_entry.view`, etc.). Settings permissions follow the shape:

```
settings.{module}.{action}
```

Two permissions ship in v1:

- `settings.hrm.view` — view the HRM Settings page
- `settings.hrm.update` — modify HRM Settings

Future module settings (`settings.accounting.view`, `settings.inventory.view`, …) extend the same namespace as those modules' settings pages ship. The `tenant_admin` role gets `settings.hrm.*`; the `viewer` role gets `settings.hrm.view` only (auditors need to understand how the system is configured but can't modify it).

The chokepoint discipline mirrors HRM controllers: admin controllers use the `AuthorizesAdminAccess` trait (`authorizeAdmin($request, 'settings.hrm.view')`) so future per-company permission scoping (the H1c refactor that `AuthorizesHrmAccess` already prepares for) is a single-flip concern across both admin and HRM controllers. Do **not** call `$user->can()` directly in admin controllers; the trait is the single ingress point.

## URL space

```
/api/v1/admin/{module}/{resource}
```

- `/api/v1/admin/hrm/settings` — show + update for HRM Settings (v1)

Admin routes mount under the same authenticated middleware group as the HRM business routes (`auth:sanctum + tenant + company`). The URL prefix (`/admin/`) makes a `grep "admin/"` audit grep-distinct from the HRM business endpoints — admin is a different sidebar, not a different security boundary.

## Frontend access pattern (Session 2)

The admin app is accessed via the **user menu** (top-right avatar dropdown), NOT via the launcher. Two reasons:

1. **Launcher = "which line of business?"** Admin isn't a line of business; it's where the tenant-admin configures the lines of business they already have access to. Mixing admin into the launcher grid would conflate "pick an app" with "configure your apps."
2. **Single-app users wouldn't see admin in the launcher anyway** — v1 has only HRM in `LAUNCHER_APPS`, and the `AppSwitcherDropdown` is hidden when fewer than 2 apps are accessible. Admins still need a direct path to the settings page; the user menu provides that path independent of the app-switching surface.

Architecturally the admin area is still a "per-app layout" in the Odoo-style refactor sense — it has its own `AdminAppLayout.vue`, its own sidebar, its own top-bar identity badge. It just isn't in the user-facing launcher grid. The `LAUNCHER_APPS` registry includes the admin entry with `hiddenFromLauncher: true`, which:

- Excludes it from `LauncherPage`'s filter chain (`.filter(a => !a.hiddenFromLauncher)`).
- Still allows `AppIdentityBadge` to surface "ADMIN" when the route's `meta.app === 'admin'`.
- Still allows `getDefaultRoute()` to fall through to admin if a user has only `settings.*` permissions (rare; future "compliance officer" persona).

## Settings storage convention

Per the slice plan's Q1 decision: settings storage is **domain-specific tables with typed columns**, NOT a generic key-value table. Each module that ships settings adds its own `{module}_settings` table — `hrm_settings` (this slice), future `accounting_settings`, etc.

Settings tables follow a consistent pattern:

- One row per `(tenant_id, company_id)`, partial unique enforced.
- Default values via DB column defaults (no application-layer defaults that drift from the schema).
- `BelongsToTenant + BelongsToCompany + Auditable` trait stack. **No SoftDeletes** — settings are 1:1 with Company.
- Cross-field consistency invariants enforced at the DB CHECK + FormRequest + Zod refinement layers (triple-stack per CLAUDE.md §3).
- Created automatically on Company creation via a domain-specific bootstrap listener (e.g. `BootstrapHrmSettingsListener` subscribed to `CompanyCreated`).
- Existing companies backfilled in the same migration that creates the settings table.

A "Settings Framework" abstraction is **NOT** built in v1. Premature — the pattern emerges concretely from the second domain's settings table (Accounting), at which point a shared base/factory may be extracted. Until then, each domain owns its settings end-to-end.

## State tables

State that's adjacent to settings but conceptually distinct (counters, sequences, derived caches) goes in a **separate** `{module}_{feature}_{noun}` table. `hrm_employee_code_sequences` is the canonical example: it holds the counter for auto-generated employee codes but is NOT a settings table — it has no Auditable trait (the counter bump on every Employee create would log noise drowning out actual settings changes), no admin UI, and lazy initialization (companies that never enable auto-gen never get a row).

Separating state from config keeps the audit log focused on user-actionable changes and isolates concurrency stories (the `SELECT FOR UPDATE` row lock on the sequence row doesn't block settings reads).

## Stage 2-5 admin features

Not implemented in v1; flagged so future sessions know the slot:

- **Stage 2** — Users management (admin-side: invite users, assign tenant roles, suspend/restore)
- **Stage 3** — Roles editor (custom per-tenant roles, permission grant matrix)
- **Stage 4** — Module Entitlement (which modules the tenant has licensed; gates app visibility in the launcher)
- **Stage 5** — Branch Admin scoping (admin permissions scoped to specific branches within a company)

Each stage adds its own sidebar entries to `AdminAppSidebar.vue` (or, if the count grows, a grouped sidebar structure). The current single-entry sidebar ("HRM Settings") establishes the pattern; subsequent entries follow it.
