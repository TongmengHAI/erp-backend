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

---

# Super Admin Portal

The Super Admin Portal is the vendor-side platform layer — a distinct identity (`user.type = 'super_admin'`) with its own URL space (`/super-admin/*`), its own SPA layout (`SuperAdminAppLayout.vue`), and its own Spatie-permission-independent gating model (user-type flag, not role-based). SA users manage the tenant estate: CRUD tenants, flip per-tenant module entitlement, view a platform-wide dashboard, suspend / resume access. Sessions 1-4 of this slice shipped the backend portion; Sessions 5-7 ship the frontend.

This section is the architecture-decision-record for SA Portal contributors. It documents the locked decisions, names the bypass sites, and surfaces the conventions future stages (Branch Admin scoping, granular SA permissions, billing) inherit.

## Identity model

The `users` table grew a `type` discriminator in Session 1 (migration `2026_06_04_100000_add_type_to_users_table.php`). Two values, locked for v1:

- `tenant_user` — normal user belonging to a tenant. Has `tenant_id NOT NULL`; participates in `TenantScope`, `ResolveTenant`, `ResolveCompany`, and Spatie role/permission scoping. The vast majority of users.
- `super_admin` — vendor-side platform operator. NO tenant or company foreign keys (composite DB CHECK enforces). Implicit access to all `/api/v1/super-admin/*` endpoints (gate is the user-type flag alone — no granular `superadmin.*` permissions in v1).

The composite `users_super_admin_no_tenant_or_company_check` constraint guarantees: if `type = 'super_admin'`, then `tenant_id`, `current_tenant_id`, `default_company_id`, and `current_company_id` are **all NULL**. The symmetric `users_tenant_user_has_tenant_check` constraint guarantees `tenant_user → tenant_id NOT NULL`. Together these two constraints close the door on a "tenant_user with no tenant" or "SA with a tenant" orphan state.

The `User::isSuperAdmin()` helper is THE canonical SA gate. Five sites key off it; do not re-check `$user->type === UserType::SuperAdmin` inline — route through the helper so a future identity-model change (e.g. adding a third user type) lands in one place.

### Creating SA users

Two paths:

- **Local dev / testing**: `Database\Seeders\Framework\SuperAdminSeeder` ships the canonical `superadmin@myerp.local / superadmin` user. The seeder is gated by `app()->environment(['local', 'testing'])` — throws `RuntimeException` if invoked in any other environment. Wired into `DatabaseSeeder` so `php artisan db:seed` produces a usable SA out of the box.
- **Production**: `php artisan super-admin:create --email=… --name=…`. The password resolves from (in priority order) `--password` flag, `SUPER_ADMIN_PASSWORD` env var, or interactive STDIN `secret()` prompt. The plaintext password is never logged or persisted in any form beyond `Hash::make` for the `users.password` BCrypt hash.

After the first SA exists, future SAs can be created via the SA Portal UI (Stage 2 — not in v1; deferred).

## User-type bypass on global scopes and middlewares

The SA identity's "no tenant, no company" shape means every tenant-scoped surface needs an SA-aware short-circuit. **Five sites** key off `isSuperAdmin()`:

| Site | File | Behaviour |
|---|---|---|
| `TenantScope::apply` | `app/Support/Tenancy/Scopes/TenantScope.php` | Returns early; query runs cross-tenant |
| `CompanyScope::apply` | `app/Support/Company/Scopes/CompanyScope.php` | Returns early; query runs cross-company |
| `ResolveTenant::handle` | `app/Support/Tenancy/Middleware/ResolveTenant.php` | Skips tenant resolution (SA has no `tenant_id` to resolve) |
| `ResolveCompany::handle` | `app/Support/Company/Middleware/ResolveCompany.php` | Skips the 5-branch company-resolution chain |
| `EnforceModuleEntitlement::handle` | `app/Web/API/V1/Middleware/EnforceModuleEntitlement.php` | Bypasses module gates; SA reaches any module's endpoints |

The pattern in all five sites is identical: `if ($authUser instanceof User && $authUser->isSuperAdmin()) return ...`. New scopes / middlewares that depend on tenant context must add the same bypass — the discipline is **the bypass is parallel, not relaxing**. Each bypass adds an SA-only branch; the non-SA path is unchanged. Regression tests in `SuperAdminBypassTest` pin this: cross-tenant `Employee::all()` returns rows for SA; tenant_user with no context still throws `TenantContextMissingException`.

When a future identity type ships (audit-bot, system, etc.), it likely needs the same five-site bypass shape with its own composite DB CHECK. Treat that as a template — same five files, same regression tests.

## Module entitlement model

The `tenant_modules` table (Session 2 — migration `2026_06_05_100000_create_tenant_modules_table.php`) is the per-tenant entitlement record. One row per `(tenant_id, module_key)` where `deleted_at IS NULL` (partial unique index). Soft-deleted rows preserve the audit-history chain — a tenant that had HRM, lost it, then re-granted it appears as a single row with one soft-delete + restore cycle.

### Schema

- `module_key` — varchar(32); free string, app-layer validated against `LAUNCHER_APPS` registry ids. Adding a module ships an entry in the registry; renaming a module key requires a data migration (the ids are contractual once shipped — see §10.6 of CLAUDE.md once the slice-closer pattern doc lands).
- `status` — varchar(16); enum `active | disabled` (DB CHECK enforced). Future statuses (`trial`, `module-suspended`) are billing concerns deferred per the explicit cuts.
- `enabled_at` / `enabled_by_user_id` — timestamp + actor. NULL `enabled_by_user_id` represents "system bootstrap" (the migration backfill, the demo seeder helper) — see "§10.12 trap avoidance" below. UI-driven syncs populate it with the acting SA's id.

### Three-layer enforcement (defense in depth)

Same source of truth — `tenant_modules` — checked at every gate:

1. **API middleware** — `EnforceModuleEntitlement` parameterised as `module:hrm` on `/api/v1/hrm/*` **and** `/api/v1/admin/hrm/*`. The same gate applies to both because tenant_admin must not be able to self-rescue an HRM disablement via the admin Settings page (only SA controls entitlement).
2. **Frontend route guard** — Vue Router beforeEach checks the user's `entitled_modules` array from `/auth/me`; route navigation into a disabled module redirects to a 404 client-side (matches the API's 403 disposition).
3. **Launcher filter** — `LAUNCHER_APPS` is filtered by `entitled_modules` for tenant_users (and by `superAdminOnly` for SA). Apps not in the user's entitlement array don't appear in the launcher grid.

The single source of truth is the `entitled_modules: string[]` field on the `/auth/me` response, populated by `TenantEntitlementService`. Every consumer routes through that field; drift between the three layers is impossible by construction.

### §10.12 trap avoidance — bootstrap rows use NULL enabled_by_user_id

The migration backfill for `tenant_modules` runs against an **empty** `users` table (migrations execute before seeders). If `enabled_by_user_id` were NOT NULL, the backfill would either fail or require referencing a phantom SA id that doesn't exist yet. The chosen shape: `enabled_by_user_id` is nullable, bootstrap rows (migration backfill + seeder helper) use NULL, UI-driven syncs populate it with the acting SA's id. Both invariants are pinned by tests so neither side regresses independently.

### SA-side endpoints

| Method | Path | Purpose |
|---|---|---|
| GET | `/api/v1/super-admin/tenants/{tenant}/modules` | List entitlement rows for a tenant |
| PATCH | `/api/v1/super-admin/tenants/{tenant}/modules` | Reconcile entitlement (upsert per `module_key` × `status`) |

Sync semantics are partial-update: a payload with `[{module_key: 'hrm', status: 'disabled'}]` disables HRM but doesn't touch any other entitlement row. Restoration of soft-deleted rows is automatic when the same `(tenant, module_key)` is re-granted — preserves the audit-history chain.

## Tenant CRUD endpoints

| Method | Path | Purpose |
|---|---|---|
| GET | `/api/v1/super-admin/tenants` | Paginated list with status filter |
| GET | `/api/v1/super-admin/tenants/{tenant}` | Detail |
| POST | `/api/v1/super-admin/tenants` | Create + initial admin (atomic; one-time password) |
| PATCH | `/api/v1/super-admin/tenants/{tenant}` | Update profile + status transitions |

No `DELETE` — destruction is out of scope for v1 (cascade complexity + UX is a separate slice).

### Atomic create with initial admin

`CreateTenantWithInitialAdminAction::execute` wraps in one `DB::transaction`:

1. Tenant insert.
2. Default Company insert (the tenant's "first company"; `CompanyCreated` event fires → `BootstrapHrmSettingsListener` materialises `hrm_settings`).
3. Initial tenant_admin User insert (`type = tenant_user`; password = `Str::password(16)` plaintext, BCrypt-hashed).
4. `tenant_admin` role assignment scoped to the new tenant.
5. HRM `tenant_modules` row (`status = Active`, `enabled_by_user_id = acting SA's id`).

The plaintext password is generated OUTSIDE the transaction so a rollback doesn't leak partial state, returned ONCE in the `data.initial_admin_password` field of the response, and never persisted to logs, audit rows, or any other store. `Auditable::filterAttributesForAudit` drops `User::$hidden` keys (`password`, `remember_token`) from every audit row — the User-creation audit event captures the actor identity without leaking the secret. A LOAD-BEARING test (`CreateTenantWithInitialAdminActionTest`) decodes the audit row's `after` JSON and asserts both the absence of the `password` key AND the absence of the plaintext string anywhere in the serialised row (defense in depth against a hypothetical future Auditable change that leaks via metadata).

### Recovery path if the SA loses the one-time display

The newly-created tenant_admin's `users.password` is a real BCrypt hash. Laravel's standard `Password` broker (and Notifications) work normally against it — the user can reset via the forgot-password flow whenever the endpoint ships. Session 3 doesn't ship the forgot-password endpoint (it's a separate auth concern); the test surface (`Hash::check` succeeds with the returned plaintext + `Password::getUser(['email' => …])` resolves the user) proves the **data shape** is recovery-ready so the endpoint can land in a future slice without coordination.

### §10.12 trap on tenant creation

`BootstrapHrmSettingsListener` listens for `CompanyCreated` and materialises `hrm_settings`. If the listener fails (deploy mid-rollout, listener exception, queue failure), the tenant + company + admin exist but `hrm_settings` doesn't — the tenant_admin would later 500 on the Settings page. Recovery: re-fire `CompanyCreated::dispatch($company)`. The listener is idempotent (`firstOrCreate`-style); re-firing closes the gap without duplicating state. A LOAD-BEARING test exercises exactly this path (kill listener → run action → assert gap → re-fire → assert recovery).

## Suspension semantics (Q1 reuse)

Suspending a tenant via `PATCH /api/v1/super-admin/tenants/{tenant}` with `{"status": "suspended"}` flips the `tenants.status` column to `Suspended` and writes an audit row via the existing `Auditable` trait. No separate session-killer is needed — suspended-tenant users' next request hits the **existing** `tenant_inactive` 401 channel:

1. The user's next request reaches `ResolveTenant::handle`.
2. `ResolveTenant::resolveFor` reads `tenants.status`; non-Active throws `TenantInactiveException` (HTTP 401, `error_code = tenant_inactive`).
3. The SPA's auth interceptor catches `tenant_inactive` and routes to `/tenant-suspended`.

The SA-triggered suspension is identical to any other Suspended-state path — Session 1 + Session 3 ship NO new infrastructure for this. Resumption (PATCH back to `Active`) reverses the flow; the tenant user's next request resolves normally.

SA users continue to see suspended tenants' data (per the bypass — `TenantScope` + `ResolveTenant` short-circuit for SA). That's the point: SA needs to manage suspended tenants (resume, view audit trail, eventually delete).

## Dashboard

`GET /api/v1/super-admin/dashboard` — single endpoint, 5 metric tiles + 2 recent-activity lists per Q6:

| Block | Source | Computation |
|---|---|---|
| `tenant_status_counts` | `tenants` | `GROUP BY status` aggregation → `{total, active, suspended, archived}` |
| `tenants_by_module` | `tenant_modules` (`deleted_at IS NULL`) | `GROUP BY module_key, status` → list of `{module_key, active_count, disabled_count}` |
| `recent_signups` | `tenants` | `WHERE created_at >= now() - 7d` ORDER BY `created_at DESC` LIMIT 10 |
| `recent_suspensions` | `tenants` | `WHERE status = Suspended AND updated_at >= now() - 7d` ORDER BY `updated_at DESC` LIMIT 10 |
| `window_days` | (constant) | `SuperAdminDashboardService::RECENT_WINDOW_DAYS` (= 7) — exposed so SPA copy ("Last 7 days") doesn't hardcode the window |

Implementation: `SuperAdminDashboardService` (read-side per §10.3 — single source of read-time truth for the metrics; future cache layer is a single-file change with consumers unchanged). Query strategy: **separate query per metric** (5 metric queries + 2 list queries = 7 round-trips per dashboard load). Each metric is independently cacheable later. Combined queries are more efficient but couple cacheability — at the dashboard's current load profile (~7 indexed queries; sub-100ms total in practice) the simpler shape wins.

The "recent suspensions" tile uses `tenants.updated_at` as a proxy for "when suspended" — assumes the most recent status change is the suspension, which is true in the SA's flow (SA flips status only via PATCH which bumps `updated_at`). When that assumption breaks (e.g. a tenant gets suspended then has unrelated profile updates), the dashboard would lose that row from the recent-suspensions list — but the SA can still find suspended tenants via the tenants list's status filter. Migrating to an `audit_logs`-backed query is the obvious upgrade path; deferred until the assumption visibly breaks.

## Authorisation chokepoint

All `/super-admin/*` routes mount under the `'super_admin'` middleware alias (`SuperAdminGuard`). Behaviour:

- Unauthenticated → already handled upstream by `auth:sanctum` (401).
- Authenticated tenant_user → 404 `NotFoundHttpException` (per Q8 — security-through-obscurity; tenant_users have no legitimate reason to know `/super-admin/*` URLs exist).
- Authenticated SA → continues.

The 404 (not 403) disposition is the convention SaaS admin tools follow (GitHub Enterprise admin, Stripe Dashboard internals). It also keeps the SA URL space out of `/api`-discovery traffic in logs.

There is no separate Spatie permission set for SA endpoints in v1. The user-type flag IS the gate. Granular SA permissions (billing-only SA, support-only SA, etc.) are deferred per the explicit cuts.

## Demo seed state

`DemoUsersSeeder` (local + testing only) ships a multi-tenant demo:

- **Acme Trading Co.** (active) — `admin@acme.test` + `manager@acme.test`; full HRM data (employees, departments, branches, positions, leave_requests, attendance, leave_balances).
- **Sokha Trading Co.** (active) — `admin@sokha.test` + `manager@sokha.test` + `viewer@sokha.test`. Same role pattern as Acme plus a third role tier (viewer) to demonstrate multi-tenant role-scoping (Spatie team_id independence).
- **Suspended Co.** (suspended) — `suspended@acme.test`; exercises the `tenant_inactive` flow.
- **`superadmin@myerp.local`** — Super Admin user (from `SuperAdminSeeder`).

All three tenants carry an Active HRM `tenant_modules` row via the seeder's `ensureTenantHasHrmEntitlement()` helper (closes the §10.12-style gap where `Tenant::query()->firstOrCreate(...)` doesn't trigger `TenantFactory::configure()`'s `afterCreating` hook).

The SA cross-tenant bypass is provably real with this seed state: `MultiTenantDemoStateTest` actually runs the seeder and asserts SA queries return all three tenants while tenant_admin queries return only their own.

## Stage 2-5 — future Super Admin features

Not implemented; flagged so future sessions know the slot:

- **Stage 2 — granular SA permissions.** Split SA access into roles (billing, support, ops) with `superadmin.*` Spatie permissions. The current implicit-access model is the right v1 default; this stage adds rigour once the SA team grows beyond one or two people.
- **Stage 3 — tenant data drill-down.** SA views employees / journal entries / etc. inside a tenant. The TenantScope SA bypass already supports this technically; the UX (a "viewing tenant X" mode bar; explicit context-switching) is the missing piece.
- **Stage 4 — billing / subscription.** Per-tenant plan, billing cycle, payment status. Module entitlement is the foundation; billing-state mappings to module entitlement is the connecting layer.
- **Stage 5 — tenant impersonation.** SA temporarily acts as a tenant user for support. High audit + UX complexity; deferred.

Each stage adds its own sub-prefix under `/api/v1/super-admin/`. The current `tenants/`, `tenants/{tenant}/modules/`, and `dashboard/` endpoints establish the pattern.
