# HRM API (v1)

This document is the canonical API contract for the HRM domain endpoints. The frontend consumes these endpoints; types in `frontend/src/modules/hrm/types/` are derived from this document, not invented separately.

## Preamble (applies to every HRM endpoint)

### Authentication

All HRM endpoints require an authenticated session. Auth is Sanctum SPA cookie-based (see [auth.md](./auth.md) for the login flow). Unauthenticated requests return **401**.

### Tenant + company context

All HRM endpoints require both a resolved tenant AND a resolved company. The middleware chain on every route is `['auth:sanctum', 'tenant', 'company']`:

1. `tenant` resolves the active tenant from `user.current_tenant_id` (fallback `user.tenant_id`) and pins it for the request.
2. `company` resolves the active company via the 5-branch chain documented in [auth.md → Company context](./auth.md#company-context). Critically, this is **required** (not optional); a user without a resolvable company gets **401** with `error_code='company_required'` and an `available_companies` array — same shape `/auth/me` returns on `company:optional`.

The `X-Company-Id` header overrides the resolved company per-request (one-shot switching from the SPA). An invalid id returns **403**.

### Isolation guarantees

Every list/show response is scoped to the resolved `(tenant_id, company_id)`. Records outside that scope are invisible — cross-tenant or cross-company access manifests as **404**, not 403. This is enforced by global Eloquent scopes (`TenantScope` + `CompanyScope`) on the underlying models; the API surface cannot accidentally leak.

### Authorization

Every endpoint is gated by a Spatie permission (the `{domain}.{resource}.{action}` pattern from `DefaultPermissionsSeeder`). Authenticated requests missing the required permission return **403**. The current permissions for HRM are:

| Permission | Granted to (default roles) |
| --- | --- |
| `hrm.employee.view` | `tenant_admin`, `viewer` |
| `hrm.employee.create` | `tenant_admin` |
| `hrm.employee.update` | `tenant_admin` |
| `hrm.employee.delete` | `tenant_admin` |
| `hrm.department.view` | `tenant_admin`, `viewer` |
| `hrm.department.create` | `tenant_admin` |
| `hrm.department.update` | `tenant_admin` |
| `hrm.department.delete` | `tenant_admin` |

### Standard error responses

| Status | Cause | Body shape |
| --- | --- | --- |
| **401** | Unauthenticated, or company context unresolved | `{message, error_code?, available_companies?}` |
| **403** | Authorization denied (missing permission, or `X-Company-Id` outside tenant) | `{message}` |
| **404** | Resource not found (or hidden by tenant/company scope) | `{message}` |
| **422** | Validation failure | `{message, errors: {field: [messages...]}}` |
| **429** | Rate limit hit (60 req/min per route) | `{message}` |

### Rate limits

HRM routes inherit Laravel's default API throttle of **60 requests per minute per authenticated user**. Frontend should not poll faster than this.

---

## Employees

### Resource shape

The full Employee shape returned by `show`, `store`, and `update`:

```json
{
    "data": {
        "id": 42,
        "employee_code": "E-1001",
        "full_name": "Sokha Chan",
        "email": "sokha.chan@acme.test",
        "job_title": "Operations Manager",
        "hire_date": "2022-03-15",
        "status": "active",
        "created_at": "2026-05-19T10:00:00+00:00",
        "updated_at": "2026-05-19T10:00:00+00:00"
    }
}
```

The list (`index`) response uses a compact shape — no `created_at`/`updated_at`/`email`:

```json
{
    "data": [
        {
            "id": 42,
            "employee_code": "E-1001",
            "full_name": "Sokha Chan",
            "job_title": "Operations Manager",
            "hire_date": "2022-03-15",
            "status": "active"
        }
    ],
    "links": { "first": "...", "last": "...", "prev": null, "next": "..." },
    "meta": { "current_page": 1, "from": 1, "to": 25, "per_page": 25, "total": 42, "last_page": 2 }
}
```

`tenant_id` and `company_id` are deliberately omitted from the response — the SPA already knows the active context from `/auth/me`.

### Field semantics

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `employee_code` | string ≤ 32 chars | yes | Unique within `(tenant, company)`. Free-form (e.g. `E-1001`, `ACME-042`, `2026-001`). |
| `full_name` | string ≤ 255 | yes | Display name. No structured first/last split in this slice. |
| `email` | string ≤ 255 or `null` | no | Standard email format if provided. Not unique. |
| `job_title` | string ≤ 255 or `null` | no | Plain text — not linked to a Positions table in this slice. |
| `hire_date` | ISO 8601 date (`YYYY-MM-DD`) | yes | Stored as DATE. No time component. |
| `status` | enum | yes | One of `active`, `on_leave`, `terminated`. |

### `EmployeeStatus` enum values

| Value | Meaning |
| --- | --- |
| `active` | Currently employed and working. |
| `on_leave` | Temporarily away (parental, medical, sabbatical). Still on payroll, still counted in headcount, but excluded from "active workforce" reports. |
| `terminated` | No longer employed. Soft-deleting an employee row does NOT auto-set this — historical employees keep whatever status they were last assigned. |

### Endpoint: GET /api/v1/hrm/employees

**Permission**: `hrm.employee.view`

List employees in the current `(tenant, company)`. Paginated.

**Query parameters:**

| Param | Type | Required | Notes |
| --- | --- | --- | --- |
| `search` | string ≤ 255 | no | Case-insensitive partial match against `full_name` OR `employee_code` (PostgreSQL ILIKE). |
| `status` | enum | no | One of `active`, `on_leave`, `terminated`. |
| `per_page` | int 1–100 | no | Default 25. |
| `page` | int ≥ 1 | no | Default 1. Standard Laravel pagination. |

**Returns**: list shape (above). Empty `data: []` is the correct empty state; the API does not return 404 for "no results".

### Endpoint: GET /api/v1/hrm/employees/{employee}

**Permission**: `hrm.employee.view`

Fetch a single employee. `{employee}` is the integer id.

**Returns**: full shape (above). **404** if the id doesn't exist, has been soft-deleted, or belongs to a different tenant/company.

### Endpoint: POST /api/v1/hrm/employees

**Permission**: `hrm.employee.create`

Create a new employee. `tenant_id` and `company_id` are derived from the request context — they MUST NOT appear in the request body (any value sent for them is silently ignored). The created row carries an audit entry with both ids populated.

**Request body**: all `Required` fields above, plus optional ones. Example:

```json
{
    "employee_code": "E-1042",
    "full_name": "New Hire",
    "email": "new@acme.test",
    "job_title": "Specialist",
    "hire_date": "2026-05-19",
    "status": "active"
}
```

**Returns**: **201 Created** with full Employee shape.

**Validation errors (422):**
- `employee_code` already in use within the current `(tenant, company)`.
- `employee_code` exceeds 32 chars or is empty.
- `email` is malformed.
- `hire_date` is not a valid date.
- `status` is not in the enum.

### Endpoint: PATCH /api/v1/hrm/employees/{employee}

**Permission**: `hrm.employee.update`

Partial update. Only fields present in the body are touched (`sometimes` validation rule). All field-level constraints from `store` apply.

**Request body** (any subset of mutable fields):

```json
{
    "job_title": "Senior Specialist",
    "status": "on_leave"
}
```

**Returns**: 200 with full Employee shape (refreshed post-save).

**Constraints**:
- `tenant_id` and `company_id` are NOT mutable — they don't appear in the request schema.
- `employee_code` can be changed but must remain unique within the company. The current row is ignored in the uniqueness check (no false-positive on self).

### Endpoint: DELETE /api/v1/hrm/employees/{employee}

**Permission**: `hrm.employee.delete`

Soft-delete an employee. The row remains in the DB with `deleted_at` set; it disappears from list/show responses immediately.

**Returns**: **204 No Content**.

**Idempotency**: a second `DELETE` on the same id returns **404** — the soft-deleted row is invisible to the scoped query. No "already deleted" body.

**Status preservation**: deleting does NOT auto-set `status='terminated'`. If you want both, update the status first, then delete.

---

## Departments

Flat organizational units within a company. No hierarchy in this slice — `parent_id`, closure tables, and an Employee↔Department FK link are explicitly deferred. Each department is a standalone record scoped to one `(tenant, company)` pair.

### Resource shape

The full Department shape returned by `show`, `store`, and `update`:

```json
{
    "data": {
        "id": 7,
        "code": "D-OPS",
        "name": "Operations",
        "description": "Day-to-day operations team.",
        "status": "active",
        "created_at": "2026-05-20T10:00:00+00:00",
        "updated_at": "2026-05-20T10:00:00+00:00"
    }
}
```

The list (`index`) response uses a compact shape — no `description`, no `created_at`/`updated_at`:

```json
{
    "data": [
        { "id": 7, "code": "D-OPS", "name": "Operations", "status": "active" }
    ],
    "links": { "first": "...", "last": "...", "prev": null, "next": "..." },
    "meta": { "current_page": 1, "from": 1, "to": 25, "per_page": 25, "total": 4, "last_page": 1 }
}
```

`tenant_id` and `company_id` are deliberately omitted — same convention as the Employees resource.

### Field semantics

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `code` | string ≤ 32 chars | yes | Unique within `(tenant, company)`. Free-form (e.g. `D-OPS`, `FIN`, `ACME-SALES`). |
| `name` | string ≤ 255 | yes | Display name. |
| `description` | string ≤ 500 or `null` | no | Short descriptor — bounded, not a notes blob. Cap enforced in 3 places: DB column, FormRequest, frontend Zod schema. |
| `status` | enum | yes | One of `active`, `archived`. |

### `DepartmentStatus` enum values

| Value | Meaning |
| --- | --- |
| `active` | Operational; appears in default lists. |
| `archived` | Retired; preserved for historical reference. Filtered out via the status filter, not deleted. |

### Endpoint: GET /api/v1/hrm/departments

**Permission**: `hrm.department.view`

List departments in the current `(tenant, company)`. Paginated.

**Query parameters:**

| Param | Type | Required | Notes |
| --- | --- | --- | --- |
| `search` | string ≤ 255 | no | Case-insensitive partial match against `name` OR `code` (PostgreSQL ILIKE). |
| `status` | enum | no | One of `active`, `archived`. |
| `per_page` | int 1–100 | no | Default 25. |
| `page` | int ≥ 1 | no | Default 1. Standard Laravel pagination. |

**Returns**: list shape (above). Empty `data: []` is the correct empty state; the API does not return 404 for "no results".

### Endpoint: GET /api/v1/hrm/departments/{department}

**Permission**: `hrm.department.view`

Fetch a single department. `{department}` is the integer id.

**Returns**: full shape (above). **404** if the id doesn't exist, has been soft-deleted, or belongs to a different tenant/company.

### Endpoint: POST /api/v1/hrm/departments

**Permission**: `hrm.department.create`

Create a new department. `tenant_id` and `company_id` are derived from the request context — they MUST NOT appear in the request body.

**Request body** example:

```json
{
    "code": "D-MKTG",
    "name": "Marketing",
    "description": "Marketing and brand team.",
    "status": "active"
}
```

**Returns**: **201 Created** with full Department shape.

**Validation errors (422):**
- `code` already in use within the current `(tenant, company)`.
- `code` exceeds 32 chars or is empty.
- `name` exceeds 255 chars or is empty.
- `description` exceeds 500 chars.
- `status` is not in the enum.

### Endpoint: PATCH /api/v1/hrm/departments/{department}

**Permission**: `hrm.department.update`

Partial update. Only fields present in the body are touched (`sometimes` rule). All field-level constraints from `store` apply, including the ignore-self uniqueness check on `code`.

### Endpoint: DELETE /api/v1/hrm/departments/{department}

**Permission**: `hrm.department.delete`

Soft-delete a department. The row remains in the DB with `deleted_at` set; it disappears from list/show responses immediately.

**Returns**: **204 No Content**.

**Idempotency**: a second `DELETE` on the same id returns **404**.

**Status preservation**: deleting does NOT auto-set `status='archived'`. If you want both, update the status first, then delete.

---

## Audit

Every create / update / delete writes an entry to `audit_logs` with:

- `tenant_id` — current tenant
- `company_id` — current company (a key H1b-pre guarantee)
- `actor_id` — the authenticated user's id
- `action` — `created` / `updated` / `soft_deleted`
- `before` / `after` — diff-only for updates, full filtered attributes for create/delete

Audit rows are append-only at the DB level (immutability trigger). No HRM endpoint exposes audit_logs in this slice; reading audit history is a future feature.

---

## Out of scope (deferred)

The following are **not** in the HRM module as shipped:

- **Employee↔Department FK link** — Departments ships standalone in this slice; the FK is a planned follow-up. Employee.job_title remains plain text until the link lands.
- Branches table, Positions table
- Hierarchy / closure tables, department parents, manager relationships
- Photo upload, address history, emergency contacts, salary
- Bulk select / bulk delete
- Audit-log read endpoint
- Per-company permission scoping (H1c) — `AuthorizesHrmAccess` chokepoint exists so H1c is a drop-in later
- Approval workflow, leave, attendance, payroll
