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
| `hrm.leave_request.view` | `tenant_admin`, `viewer` |
| `hrm.leave_request.create` | `tenant_admin` |
| `hrm.leave_request.update` | `tenant_admin` |
| `hrm.leave_request.delete` | `tenant_admin` |
| `hrm.leave_request.approve` | `tenant_admin` |
| `hrm.attendance.view` | `tenant_admin`, `viewer` |
| `hrm.attendance.create` | `tenant_admin` |
| `hrm.attendance.update` | `tenant_admin` |
| `hrm.attendance.delete` | `tenant_admin` |

`hrm.leave_request.approve` represents **decision-making authority** — it gates both the `/approve` and `/reject` endpoints. A manager who can decide on requests has decision authority, not "approval-only authority." This means a future "team_lead" role can be granted `.view + .approve` (decide on requests, no CRUD), and a future "employee" role can be granted `.view + .create + .update + .delete` (manage their own requests, no decisions) — without needing to split the permission later in a way that breaks existing role assignments.

### Standard error responses

| Status | Cause | Body shape |
| --- | --- | --- |
| **401** | Unauthenticated, or company context unresolved | `{message, error_code?, available_companies?}` |
| **403** | Authorization denied (missing permission, or `X-Company-Id` outside tenant) | `{message}` |
| **404** | Resource not found (or hidden by tenant/company scope) | `{message}` |
| **422** | Validation failure | `{message, errors: {field: [messages...]}}` |
| **422** | Invalid state transition (Leave Requests only) | `{message, error_code: "invalid_transition", from, to}` |
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
        "department": {
            "id": 1,
            "code": "D-OPS",
            "name": "Operations"
        },
        "hire_date": "2022-03-15",
        "status": "active",
        "created_at": "2026-05-19T10:00:00+00:00",
        "updated_at": "2026-05-19T10:00:00+00:00"
    }
}
```

`department` is `null` when the employee has no current department, or when the assigned department has been soft-deleted (the FK still references the tombstone row but the relation returns null).

The list (`index`) response uses a compact shape — no `created_at`/`updated_at`/`email`, and `department` is flattened to a single `department_name` string:

```json
{
    "data": [
        {
            "id": 42,
            "employee_code": "E-1001",
            "full_name": "Sokha Chan",
            "department_name": "Operations",
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
| `department_id` | int or `null` | no | FK → `departments.id`. MUST point at a department in the same `(tenant, company)` — enforced via scoped `exists` validation. A foreign-tenant or foreign-company id returns **422** with `errors.department_id`. ON DELETE SET NULL: if the department is hard-deleted, the field clears; soft-delete leaves the FK but the relation returns null. |
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
| `department_id` | int | no | Filter to a specific department. Cross-tenant or cross-company ids silently return empty results (the global scopes don't match) — no 422, no leak. Used by the Department detail page's "View employees" link. |
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
    "department_id": 1,
    "hire_date": "2026-05-19",
    "status": "active"
}
```

**Returns**: **201 Created** with full Employee shape.

**Validation errors (422):**
- `employee_code` already in use within the current `(tenant, company)`.
- `employee_code` exceeds 32 chars or is empty.
- `email` is malformed.
- `department_id` does not exist, OR belongs to a different `(tenant, company)`, OR points at a soft-deleted department.
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

Flat organizational units within a company. No hierarchy in this slice — `parent_id` and closure tables are explicitly deferred. Each department is a standalone record scoped to one `(tenant, company)` pair. Employees may be assigned to a department via the optional `department_id` FK on the Employee resource.

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
        "employees_count": 2,
        "created_at": "2026-05-20T10:00:00+00:00",
        "updated_at": "2026-05-20T10:00:00+00:00"
    }
}
```

`employees_count` is a derived field — the count of employees in the same `(tenant, company)` currently assigned to this department. Pre-computed via `withCount('employees')` on the show endpoint, so the response is one row fetch + one count subquery. The list (Brief) shape does NOT include this count.

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

## Leave Requests

A leave request is an employee's request for time off. Unlike Employees and Departments — which are pure CRUD resources — a leave request carries a **workflow state**: it starts as `pending` and transitions exactly once into a terminal state (`approved` or `rejected`). The transition is performed by a user with decision-making authority.

### State machine

```
                ┌──────────────────► approved (terminal)
                │
   pending ─────┤
                │
                └──────────────────► rejected (terminal)
```

- New requests always land in `pending`. The `status` field is **not** validated at the create endpoint — even if a client submits `status="approved"`, the value is dropped and the row is created as pending.
- From `pending`, the row may transition to `approved` via `POST /approve`, or to `rejected` via `POST /reject`. Both are gated by the `hrm.leave_request.approve` permission.
- Terminal states are **read-only at the edit layer**. `PATCH` on an `approved` or `rejected` row returns **422** with `error_code="invalid_transition"`. The Delete affordance still works (the row may have been created in error) — see the deliberate edit/delete asymmetry below.
- There is no "re-open" transition. A wrongly-decided request is deleted and re-submitted; or — for accounting-grade rigor — handled by a future audit-trail-preserving compensation flow (not in this slice).

### Why the approval columns live on the row (not just in the audit log)

The decision (`approved_by`, `approved_at`, `approver_note`) lives on `leave_requests` itself, not derived from `audit_logs`. Two reasons:

1. **Read performance.** Every list row needs "decided by X on Y date" in the column. Reconstructing that from the audit log per request would require a join + filter on every render.
2. **Survivability.** The audit log is for historical replay; the columns are the **current state**. If the audit log were ever pruned (compliance retention policy), the decision metadata would still be present on the row.

A composite DB CHECK constraint guarantees consistency: a `pending` row MUST have NULL approval columns; a non-`pending` row MUST have all approval columns populated. The Approve/Reject Actions write all three columns in a single save — defense in depth against partial state.

### Edit vs Delete asymmetry on decided rows

- **Edit** (`PATCH`): blocked on decided rows. The approver's decision was made against specific facts (dates, type, reason); editing them after approval would invalidate the meaning of the approval.
- **Delete** (`DELETE`): allowed on decided rows. The "created in error" affordance survives the decision — a wrongly-submitted request can still be removed by anyone with `.delete` permission. Soft-delete preserves the audit row.

### Full resource shape

The full LeaveRequest shape returned by `show`, `store`, `update`, `approve`, `reject`:

```json
{
    "data": {
        "id": 17,
        "employee": {
            "id": 42,
            "employee_code": "E-1001",
            "full_name": "Sokha Chan"
        },
        "leave_type": "annual",
        "start_date": "2026-06-15",
        "end_date": "2026-06-19",
        "day_part": "full_day",
        "reason": "Family wedding in Siem Reap.",
        "status": "pending",
        "approval": null,
        "created_at": "2026-05-24T10:00:00+00:00",
        "updated_at": "2026-05-24T10:00:00+00:00"
    }
}
```

For decided rows, `approval` is populated:

```json
"approval": {
    "approved_at": "2026-05-24T14:30:00+00:00",
    "approver": {
        "id": 5,
        "name": "Manager User"
    },
    "note": "Coverage arranged with Dara."
}
```

`approval.approver` may be `null` if the approver user was hard-deleted (the FK is `ON DELETE SET NULL` — the decision survives without an attributed actor; the audit log preserves the full actor history regardless).

### List shape (compact)

The `index` response is compact for table density. Approval metadata is flattened to `approved_at` + `approver_name`:

```json
{
    "data": [
        {
            "id": 17,
            "employee_id": 42,
            "employee_name": "Sokha Chan",
            "employee_code": "E-1001",
            "leave_type": "annual",
            "start_date": "2026-06-15",
            "end_date": "2026-06-19",
            "day_part": "full_day",
            "status": "pending",
            "approved_at": null,
            "approver_name": null
        }
    ],
    "meta": { "current_page": 1, "per_page": 25, "total": 17 }
}
```

### Enum values

- `leave_type`: `annual`, `sick`, `unpaid`, `other`
- `status`: `pending`, `approved`, `rejected`
- `day_part`: `full_day` (default), `morning`, `afternoon`

### Day-part granularity

Leave requests support half-day granularity via the `day_part` field. Values:

- `full_day` (default) — request spans entire workdays. `start_date` and `end_date` may differ (a multi-day range).
- `morning` / `afternoon` — half-day request on a single date. **`start_date` MUST equal `end_date`.**

The single-date invariant for half-day requests is enforced at three layers (defense in depth):

1. Zod refinement on the frontend form (rejects locally before round-trip)
2. Closure rule on `StoreLeaveRequestRequest` and `UpdateLeaveRequestRequest` (returns 422 with `errors.end_date` or `errors.day_part` depending on which field is in the payload)
3. Composite DB CHECK constraint `leave_requests_day_part_single_date_check` (final guard against direct SQL or bypass via raw migrations)

A PATCH that changes only `day_part` to `morning` on a row whose existing dates differ is also caught — the FormRequest closures read effective post-patch values via route binding.

Hourly granularity (e.g. "Tuesday 9am–12pm") is **not** supported and is out of scope — see the Out-of-scope section.

### Endpoint: GET /api/v1/hrm/leave-requests

**Permission**: `hrm.leave_request.view`

**Query parameters** (all optional):

| Param | Type | Notes |
| --- | --- | --- |
| `employee_id` | int | Scope to a single employee's requests. |
| `status` | string | One of `pending` / `approved` / `rejected`. |
| `leave_type` | string | One of the leave_type enum values. |
| `from` | date | Lower bound — requests with `end_date >= from`. |
| `to` | date | Upper bound — requests with `start_date <= to`. |
| `per_page` | int (1–100) | Defaults to 25. |

Default sort: `created_at DESC` — the newest pending requests rise to the top of a manager's inbox view.

### Endpoint: GET /api/v1/hrm/leave-requests/{leaveRequest}

**Permission**: `hrm.leave_request.view`

Returns the full LeaveRequest shape. Cross-tenant / cross-company / soft-deleted ids return **404**.

### Endpoint: POST /api/v1/hrm/leave-requests

**Permission**: `hrm.leave_request.create`

Create a new pending request.

**Request body** example (full-day range):

```json
{
    "employee_id": 42,
    "leave_type": "annual",
    "start_date": "2026-06-15",
    "end_date": "2026-06-19",
    "reason": "Family wedding in Siem Reap."
}
```

**Request body** example (half-day):

```json
{
    "employee_id": 42,
    "leave_type": "sick",
    "start_date": "2026-06-20",
    "end_date": "2026-06-20",
    "day_part": "afternoon",
    "reason": "Doctor's appointment."
}
```

`day_part` is optional and defaults to `full_day` when omitted.

**Returns**: **201 Created** with the full shape (status will be `pending`).

**Validation errors (422):**
- `employee_id` is missing, not an integer, or does not point at a same-tenant + same-company + non-soft-deleted employee (load-bearing isolation guard).
- `leave_type` is missing or not in the enum.
- `start_date` / `end_date` are missing or not valid dates.
- `end_date` is before `start_date`.
- `day_part` is not in the enum.
- `day_part` is `morning` or `afternoon` AND `start_date` != `end_date` (half-day requests are single-date by definition).
- `reason` exceeds 500 characters.

`status` and approval-related fields are **silently dropped** if submitted — the FormRequest doesn't validate them and the Action force-sets the row to `pending` with null approval columns.

### Endpoint: PATCH /api/v1/hrm/leave-requests/{leaveRequest}

**Permission**: `hrm.leave_request.update`

Partial update of non-status fields on a **pending** request. All field constraints from `store` apply (`sometimes` rule lets fields be omitted).

`status`, `approved_by`, `approved_at`, `approver_note` are not accepted at this layer — the only path into terminal states is `/approve` and `/reject`.

**Transition error (422):** PATCH on a non-pending row returns:

```json
{
    "message": "Cannot edit a leave request that is approved. Decided requests are read-only.",
    "error_code": "invalid_transition",
    "from": "approved",
    "to": "approved"
}
```

The `from` and `to` fields carry the same value here because the user wasn't trying to transition; they were trying to edit a state that forbids editing. The shape is identical to the `/approve` and `/reject` transition errors so the client renders one error path.

### Endpoint: DELETE /api/v1/hrm/leave-requests/{leaveRequest}

**Permission**: `hrm.leave_request.delete`

Soft-delete a request. The row remains in the DB with `deleted_at` set; it disappears from list/show responses immediately.

**Returns**: **204 No Content**.

**Allowed on decided rows.** Unlike `PATCH`, `DELETE` is **not** gated by status — see the edit/delete asymmetry note above.

### Endpoint: POST /api/v1/hrm/leave-requests/{leaveRequest}/approve

**Permission**: `hrm.leave_request.approve` (the **decision-making** permission — gates this endpoint AND `/reject`).

Transition a pending request to `approved`. Sets `approved_by` to the authenticated user, `approved_at` to the current instant, `approver_note` to the optional `note` from the body.

**Request body:**

```json
{
    "note": "Coverage arranged with Dara."
}
```

`note` is optional (`null` is fine) and capped at 500 characters.

**Returns**: **200 OK** with the full LeaveRequest shape including the populated `approval` block.

**Transition error (422):** Calling `/approve` on a non-pending row returns the standard invalid-transition shape. Examples:

- Already approved (double-approve): `{from: "approved", to: "approved"}`
- Already rejected: `{from: "rejected", to: "approved"}`

### Endpoint: POST /api/v1/hrm/leave-requests/{leaveRequest}/reject

**Permission**: `hrm.leave_request.approve`

Mirror of `/approve`. Transition a pending request to `rejected`. Same request body, same response shape (with `status="rejected"`).

**Transition error (422):** Calling `/reject` on a non-pending row returns the standard shape:

- Already rejected: `{from: "rejected", to: "rejected"}`
- Already approved: `{from: "approved", to: "rejected"}`

---

## Attendance

An attendance record describes what happened for one employee on one date — present, absent, late, on leave, or half-day — with optional clock-in / clock-out times and notes. Unlike Leave Requests, attendance is **admin-entered**: managers record what they observed. No clock-in button, no biometric integration, no calculations on top (no "hours worked" or "late by N minutes" derivations).

### Relationship to Leave Requests

`status = "on_leave"` is **a manual label, not a derivation**. An attendance record with `status="on_leave"` can exist regardless of whether the employee has an approved leave request for that date, and an approved leave request can exist regardless of whether the corresponding attendance record was created. This deliberate decoupling defers the integration to the Leave Balances slice (HRM v1 path slice 4), which has to read Leave Requests anyway for the deduction math — that's the natural slice to introduce the cross-module dependency.

For this slice: the admin records what happened on the day. If the employee was on leave with an approved request, the admin records `status="on_leave"`. If they took unpaid leave without filing a request, same status. The data model accepts both.

### Uniqueness — one record per employee per date

A composite **partial unique index** `(tenant_id, company_id, employee_id, date) WHERE deleted_at IS NULL` enforces that an employee has at most one non-deleted attendance record per date. Soft-deleted rows don't block re-creation (typical "deleted a wrong entry, want to re-create" workflow).

When a POST violates this constraint, the response is **422** with the error attached to the `date` field (date is the more likely typo — managers pick the employee first, then the date), and the message names both fields:

```json
{
    "message": "...",
    "errors": {
        "date": ["Attendance for Sokha Chan on 2026-05-15 already exists."]
    }
}
```

PATCH is naturally idempotent on the (employee, date) pair — the conflict check ignores the row being updated. Changing `employee_id` or `date` on a PATCH re-runs the uniqueness check against effective post-patch values; if the new combination collides with another row, same 422 shape.

The composite partial unique index is the DB backstop. If a future refactor somehow bypassed the FormRequest, the DB would surface a 500 — not graceful but not corrupt either.

### Full resource shape

```json
{
    "data": {
        "id": 17,
        "employee": {
            "id": 42,
            "employee_code": "E-1001",
            "full_name": "Sokha Chan"
        },
        "date": "2026-05-14",
        "clock_in": "09:45:00",
        "clock_out": "18:00:00",
        "status": "late",
        "notes": "Train delay.",
        "created_at": "2026-05-14T18:30:00+00:00",
        "updated_at": "2026-05-14T18:30:00+00:00"
    }
}
```

`employee` may be `null` if the parent employee row was soft-deleted (rare but real — the attendance history survives the employee's archive).

`clock_in` / `clock_out` are `HH:MM:SS` strings matching the Postgres TIME column wire format. Both are nullable — `absent` and `on_leave` rows typically have neither; `half_day` may have one. No DB-level cross-rule between `status` and the clock columns at this slice (loose by design — admins occasionally need to override conventional patterns).

### List shape

```json
{
    "data": [
        {
            "id": 17,
            "employee_id": 42,
            "employee_name": "Sokha Chan",
            "employee_code": "E-1001",
            "date": "2026-05-14",
            "clock_in": "09:45:00",
            "clock_out": "18:00:00",
            "status": "late"
        }
    ],
    "meta": { "current_page": 1, "per_page": 25, "total": 17 }
}
```

Default sort: `date DESC, id DESC` — newest records surface first (manager's "what happened recently" view). Drops `notes`, `created_at`, `updated_at` for payload efficiency.

### Enum values

- `status`: `present`, `absent`, `late`, `on_leave`, `half_day`

### Endpoint: GET /api/v1/hrm/attendance

**Permission**: `hrm.attendance.view`

**Query parameters** (all optional):

| Param | Type | Notes |
| --- | --- | --- |
| `employee_id` | int | Scope to a single employee. |
| `status` | string | One of the status enum values. |
| `from` | date (YYYY-MM-DD) | Lower bound — records with `date >= from`. |
| `to` | date (YYYY-MM-DD) | Upper bound — records with `date <= to`. |
| `per_page` | int (1–100) | Defaults to 25. |

### Endpoint: GET /api/v1/hrm/attendance/{attendance}

**Permission**: `hrm.attendance.view`

Returns the full shape. Cross-tenant / cross-company / soft-deleted ids return **404**.

### Endpoint: POST /api/v1/hrm/attendance

**Permission**: `hrm.attendance.create`

**Request body** example:

```json
{
    "employee_id": 42,
    "date": "2026-05-14",
    "clock_in": "09:45:00",
    "clock_out": "18:00:00",
    "status": "late",
    "notes": "Train delay."
}
```

**Returns**: **201 Created** with the full shape.

**Validation errors (422):**
- `employee_id` is missing, not an integer, or does not point at a same-tenant + same-company + non-soft-deleted employee (load-bearing isolation guard).
- `date` is missing or not a valid date.
- `date` — uniqueness conflict: `"Attendance for {employee name} on {date} already exists."` (the named-fields message — see Uniqueness subsection).
- `clock_in` / `clock_out` not matching `HH:MM:SS` format.
- `clock_out` — clock-order violation: `"Clock out must be on or after clock in."` when both are set and end precedes start.
- `status` is missing or not in the enum.
- `notes` exceeds 500 characters.

### Endpoint: PATCH /api/v1/hrm/attendance/{attendance}

**Permission**: `hrm.attendance.update`

Partial update. All field constraints from `store` apply (`sometimes` rule lets fields be omitted). The uniqueness re-check ignores the row being updated; the clock-order check uses effective post-patch values (input fallback to the existing row).

### Endpoint: DELETE /api/v1/hrm/attendance/{attendance}

**Permission**: `hrm.attendance.delete`

Soft-delete. Returns **204 No Content**. A subsequent re-create for the same `(employee, date)` works because the partial unique index excludes soft-deleted rows.

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

- Branches table, Positions table
- Employee transfer history — no `previous_department_id`, no transfers table; changes to `department_id` are captured generically via `audit_logs` like any other field change
- Department hierarchy / closure tables, department parents, manager relationships
- Photo upload, address history, emergency contacts, salary
- Bulk select / bulk delete, bulk department reassignment
- Audit-log read endpoint
- Per-company permission scoping (H1c) — `AuthorizesHrmAccess` chokepoint exists so H1c is a drop-in later
- Payroll (Attendance ships now — see Attendance section above)
- Attendance-related: bulk CSV import, device/biometric integration, automatic clock-in/out buttons, "hours worked" / "late by N minutes" calculations, calendar view, reports
- Coupling between Attendance and Leave Requests (deferred to the Leave Balances slice)
- A generalized approval workflow primitive (the `app/Support/Workflow/` module). The leave-request state machine is currently bespoke to the resource; if/when a second approval flow lands (e.g. accounting journal entries, purchase requisitions) the shared workflow primitive is the right factoring point. Until then, premature.
- Re-open transitions for decided leave requests (delete + re-submit covers the current need)
- Calendar / cross-team overlap views, leave balances, accruals, hourly requests (the half-day case IS supported — see the Day-part subsection)
- Email/Slack notifications to the requester or manager
- Bulk approve/reject
