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
| `hrm.branch.view` | `tenant_admin`, `viewer` |
| `hrm.branch.create` | `tenant_admin` |
| `hrm.branch.update` | `tenant_admin` |
| `hrm.branch.delete` | `tenant_admin` |
| `hrm.position.view` | `tenant_admin`, `viewer` |
| `hrm.position.create` | `tenant_admin` |
| `hrm.position.update` | `tenant_admin` |
| `hrm.position.delete` | `tenant_admin` |

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
        "department": {
            "id": 1,
            "code": "D-OPS",
            "name": "Operations"
        },
        "position": {
            "id": 1,
            "code": "P-OPS-MGR",
            "title": "Operations Manager"
        },
        "hire_date": "2022-03-15",
        "status": "active",
        "created_at": "2026-05-19T10:00:00+00:00",
        "updated_at": "2026-05-19T10:00:00+00:00"
    }
}
```

Both `department` and `position` are `null` when the employee has no current value, or when the assigned record has been soft-deleted (the FK still references the tombstone row but the relation returns null).

The list (`index`) response uses a compact shape — no `created_at`/`updated_at`/`email`, and the nested objects are flattened to single `department_name` / `position_title` strings:

```json
{
    "data": [
        {
            "id": 42,
            "employee_code": "E-1001",
            "full_name": "Sokha Chan",
            "department_name": "Operations",
            "position_title": "Operations Manager",
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
| `department_id` | int or `null` | no | FK → `departments.id`. MUST point at a department in the same `(tenant, company)` — enforced via scoped `exists` validation. A foreign-tenant or foreign-company id returns **422** with `errors.department_id`. ON DELETE SET NULL: if the department is hard-deleted, the field clears; soft-delete leaves the FK but the relation returns null. |
| `position_id` | int or `null` | no | FK → `positions.id`. Replaces the old free-text `job_title` column dropped in the Positions slice. Same load-bearing scoped-`exists` validation as `department_id` — foreign-context ids return **422** with `errors.position_id`. ON DELETE SET NULL. |
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
| `position_id` | int | no | Filter to employees holding a specific position. Same silent-empty semantics as `department_id`. Used by the Position detail page's "Employees with this position" link. |
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
    "department_id": 1,
    "position_id": 5,
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
- `position_id` does not exist, OR belongs to a different `(tenant, company)`, OR points at a soft-deleted position.
- `hire_date` is not a valid date.
- `status` is not in the enum.

### Endpoint: PATCH /api/v1/hrm/employees/{employee}

**Permission**: `hrm.employee.update`

Partial update. Only fields present in the body are touched (`sometimes` validation rule). All field-level constraints from `store` apply.

**Request body** (any subset of mutable fields):

```json
{
    "position_id": 5,
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
        "days_count": 5.0,
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
            "days_count": 5.0,
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

### Days count

Every leave request carries a `days_count` field — the calendar-day count derived from `start_date`, `end_date`, and `day_part`. It's a stored column, populated by `CreateLeaveRequestAction` on insert and recomputed by `UpdateLeaveRequestAction` whenever any of those three inputs changes (editing `reason` alone leaves `days_count` untouched). The single source of computation truth is `App\Domain\HRM\Support\LeaveDaysCalculator`.

Rules:

- `day_part: 'full_day'` → `(end_date − start_date) + 1` calendar days, inclusive of both endpoints.
- `day_part: 'morning'` or `'afternoon'` → `0.5` by definition (the single-date invariant means `start_date == end_date`).

The column is `DECIMAL(5,1)`, `NOT NULL`, with a `CHECK (days_count > 0)` constraint. The migration that adds the column backfills existing rows with raw SQL whose expression is byte-equivalent to the Calculator's PHP — equivalence asserted by `LeaveRequestDaysBackfillEquivalenceTest`.

**v1 computes leave days as calendar days; business-day awareness (weekends, holidays, per-tenant calendar) is a future slice.** Implementing it requires inputs that don't exist yet (holiday calendars per country, weekend definitions per market, per-employee shift overrides) — out of scope here. When it lands, the Calculator gains a strategy interface and the `LeaveDaysCalculator` becomes the default strategy.

`days_count` is the load-bearing input for the upcoming Leave Balances slice: balance consumption is `SUM(days_count) WHERE status = 'approved'`, grouped by `(employee_id, leave_type, period_year)`. The stored column means the balance aggregate is a clean SQL `SUM` rather than a `CASE WHEN` that has to mirror the day-part rules.

Curious users on the Leave Request detail page can see balance impact under **Leave Balances** (separate page) — the LR workflow does not enforce balance limits in v1.

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

## Positions

A position is a structured role label (e.g. "Operations Manager", "Senior Accountant"). Replaces the free-text `employees.job_title` column that existed before this slice — see the Migration discipline subsection below.

Positions are tenant + company scoped, status-flagged (`active` / `archived`), and referenced by employees via the nullable `employees.position_id` FK. Same shape as Departments.

### Relationship to Departments

**Positions are department-agnostic.** A Position has NO FK to Department. The Employee carries `department_id` AND `position_id` as two independent dimensions ("who reports to which team" and "what is their role" are orthogonal in v1). If the data later shows that Positions cluster strongly by Department, the coupling can be added non-destructively as a future slice.

### Full resource shape

```json
{
    "data": {
        "id": 5,
        "code": "P-OPS-MGR",
        "title": "Operations Manager",
        "description": "Heads day-to-day operations.",
        "status": "active",
        "employees_count": 3,
        "created_at": "2026-05-30T10:00:00+00:00",
        "updated_at": "2026-05-30T10:00:00+00:00"
    }
}
```

`employees_count` is computed via `loadCount('employees')` on the show endpoint — single subquery, not per-row N+1. Same pattern as `Department.employees_count`.

### List shape

```json
{
    "data": [
        { "id": 5, "code": "P-OPS-MGR", "title": "Operations Manager", "status": "active" }
    ],
    "meta": { "current_page": 1, "per_page": 25, "total": 7 }
}
```

Default sort: `title ASC`. List drops `description`, `employees_count`, and timestamps for payload efficiency.

### Enum values

- `status`: `active`, `archived`

### Endpoints

All five standard CRUD endpoints under `/api/v1/hrm/positions/`. Same shape as Departments — see that section for filter/error/permission patterns. Specific notes:

- **POST** validation: `code` ≤ 32, unique within `(tenant, company)` (partial unique index excludes soft-deleted rows so re-creating after delete works), `title` ≤ 255 required, `description` ≤ 500 nullable, `status` enum required
- **PATCH** validation: same rules, `sometimes` modifier, ignore-self on the uniqueness check
- **GET** list: filters `?search=`, `?status=`, `?per_page=`. Search is case-insensitive ILIKE against `title` OR `code`
- **DELETE**: soft-delete. Re-creating with same code works after delete (partial unique index discipline)

---

## Positions migration discipline (production deployment sequence)

The Positions slice REPLACED the free-text `employees.job_title` column with the structured `employees.position_id` FK. Three schema migrations + one data migration step were required:

1. `2026_05_30_*_create_positions_table.php` — creates the new table (additive)
2. `2026_05_30_*_add_position_id_to_employees_table.php` — adds the nullable FK column to `employees` (additive; leaves `position_id = NULL` on all existing rows)
3. **Per-tenant data-migration command** — iterates each tenant's distinct `job_title` values, creates Position records, sets `position_id` on each employee accordingly. NOT bundled into any schema migration; lives in the deployment runbook (which does not exist yet — this slice flags that production requires one)
4. `2026_05_30_*_drop_job_title_from_employees_table.php` — destroys the old free-text column

### ⚠️ Production warning ⚠️

**Do NOT run the `drop_job_title_from_employees` migration in production until the per-tenant data-migration command has completed on this tenant.** Running steps 1, 2, and 4 consecutively without step 3 LOSES all `job_title` values irrecoverably — the dropping migration deletes the column before any row has its `position_id` populated.

The dev workflow is safe (the dev DB is reset frequently via `migrate:fresh` + `DemoUsersSeeder`, which creates Positions before Employees). Production has no equivalent reset; deployment must serialize the four steps in order.

### Rollback

The slice is reversible at the cost of one forward migration:

```
ALTER TABLE employees ADD COLUMN job_title VARCHAR(255) NULL;
UPDATE employees SET job_title = (SELECT title FROM positions WHERE id = employees.position_id);
ALTER TABLE employees DROP COLUMN position_id;
DROP TABLE positions;
```

The lossiness: if a Position was renamed after migration (e.g. "Operations Manager" → "Operations Director"), the rollback copies the renamed value, not the original. Acceptable — the rollback is itself an action with information, not a time machine.

---

## Branches

A branch is a physical location an Employee may be assigned to (HQ, warehouse, regional office). Pure CRUD; same shape as Department + Position with additional physical-location fields. Third cross-module FK on Employee (after department_id and position_id) — the pattern is mechanical at this point.

### Fields

Standard option from the v1 design call:

- `code` (varchar 32, required) — human-friendly identifier (e.g. `B-PNH-HQ`)
- `name` (varchar 255, required)
- `description` (varchar 500, optional)
- `address` (varchar 500, optional) — single free-text address line, not multi-line
- `city` (varchar 100, optional)
- `country_code` (varchar 2, optional) — ISO 3166-1 alpha-2, validated via regex `^[A-Z]{2}$` on the FormRequest
- `phone` (varchar 32, optional) — permissive format ("+855 23 123 456")
- `status` — `active` or `archived`

Multi-line addresses, postal codes, state/province, GPS, operating hours, multiple addresses per branch, branch hierarchies, branch managers, and per-branch role scoping are all out of scope for v1.

### country_code validation discipline

**Validated only at the FormRequest layer**, not via a DB CHECK constraint. The regex `^[A-Z]{2}$` lives on `StoreBranchRequest::rules` and `UpdateBranchRequest::rules`. The DB column is `varchar(2)` with no CHECK.

This is fine for v1 because the FormRequest is the only ingestion path in the codebase. **If a future slice introduces a CSV import, bulk admin tool, or any other path that bypasses FormRequest validation, that path MUST either:**

1. Route the data through `StoreBranchRequest` / `UpdateBranchRequest` (preferred), OR
2. Add a DB CHECK constraint via a new migration:
   ```sql
   ALTER TABLE branches ADD CONSTRAINT branches_country_code_format_check
       CHECK (country_code IS NULL OR country_code ~ '^[A-Z]{2}$');
   ```

The seeder uses `'KH'` uppercase consistently — matches the regex. If you grep the codebase for `country_code` you should find no lowercase / mixed-case literals outside of test fixtures that explicitly exercise the validator's reject path.

### Resource shape

The full Branch shape returned by `show`, `store`, `update`:

```json
{
    "data": {
        "id": 1,
        "code": "B-PNH-HQ",
        "name": "Phnom Penh HQ",
        "description": "Main headquarters and executive offices.",
        "address": "Building 5, Street 240, Sangkat Boeung Raing, Khan Daun Penh",
        "city": "Phnom Penh",
        "country_code": "KH",
        "phone": "+855 23 123 456",
        "status": "active",
        "employees_count": 3,
        "created_at": "2026-05-31T10:00:00+00:00",
        "updated_at": "2026-05-31T10:00:00+00:00"
    }
}
```

The list (`index`) response uses a compact shape — drops `description`, address/country/phone, `employees_count`, timestamps. **Includes `city`** because location is the at-a-glance differentiator between branches with similar names:

```json
{
    "data": [
        {
            "id": 1,
            "code": "B-PNH-HQ",
            "name": "Phnom Penh HQ",
            "city": "Phnom Penh",
            "status": "active"
        }
    ],
    "links": { "first": "...", "last": "...", "prev": null, "next": "..." },
    "meta": { "current_page": 1, "from": 1, "to": 25, "per_page": 25, "total": 4, "last_page": 1 }
}
```

This makes BranchBrief one field wider than DepartmentBrief / PositionBrief — deliberate departure justified by the domain. The Employee detail page reads `employee.branch.city` + `country_code` from the FULL Employee resource (nested branch snapshot is wider on Employee specifically); the Employee list page reads only `employee.branch_name` flat.

### Endpoints

Standard 5 — `GET /api/v1/hrm/branches`, `GET /api/v1/hrm/branches/{id}`, `POST`, `PATCH`, `DELETE`. Each gated by the matching `hrm.branch.*` permission. Index supports `?status=` filter and `?search=` matching name OR code OR city (ILIKE). Default sort: name ASC.

### Validation errors (422)

- `code` already in use within the current (tenant, company)
- `code` exceeds 32 chars or is empty
- `name` exceeds 255 chars or is empty
- `description` / `address` exceed 500 chars
- `city` exceeds 100 chars
- `country_code` doesn't match `^[A-Z]{2}$` (rejects lowercase, non-ASCII, wrong length)
- `phone` exceeds 32 chars
- `status` is not in the enum

### Employee `branch_id` link

`employees.branch_id` is a nullable FK to `branches.id` with `ON DELETE SET NULL`. Same shape as `department_id` and `position_id`. Validated via scoped-exists Rule on the `StoreEmployeeRequest` / `UpdateEmployeeRequest`:

```php
'branch_id' => [
    'sometimes', 'nullable', 'integer',
    Rule::exists('branches', 'id')->where(fn ($q) => $q
        ->where('tenant_id', $tenantId)
        ->where('company_id', $companyId)
        ->whereNull('deleted_at')),
],
```

Foreign-tenant / foreign-company / soft-deleted branch ids return **422** with `errors.branch_id`. Load-bearing — same isolation guard as the other cross-module FKs.

Employee's full resource carries a nested `branch` snapshot **wider than department / position** (includes `city` and `country_code`):

```json
"branch": {
    "id": 1,
    "code": "B-PNH-HQ",
    "name": "Phnom Penh HQ",
    "city": "Phnom Penh",
    "country_code": "KH"
}
```

The Employee detail page renders `"Phnom Penh HQ — Phnom Penh, KH"` from a single fetch. Employee's list (`brief`) carries only `branch_name` flat — `city`/`country_code` don't bleed into every list row.

---

## Leave Balances

Allocated leave days per `(employee, leave_type, period_year)` minus consumed days. The first slice in HRM v1 with computed/derived state — `remaining_days` is **not stored**, it's an aggregate over approved `leave_requests` computed at read time.

### Permissions reference

| Permission | Default role grants |
| --- | --- |
| `hrm.leave_balance.view` | `tenant_admin`, `viewer` |
| `hrm.leave_balance.create` | `tenant_admin` |
| `hrm.leave_balance.update` | `tenant_admin` |
| `hrm.leave_balance.delete` | `tenant_admin` |

### Stored vs computed — the load-bearing design decision

`leave_balances` has:

- `allocated_days` (stored) — what the company granted, editable via `PATCH`.
- `consumed_days` (NOT stored) — `SUM(leave_requests.days_count) WHERE status='approved' AND deleted_at IS NULL` for the same `(employee, leave_type, period_year)`.
- `remaining_days` (NOT stored) — `allocated_days - consumed_days`. **Can be negative** when an employee is over-consumed.

The aggregate is encapsulated in `App\Domain\HRM\Services\LeaveBalanceQueryService::query()` — a single source of read-time truth that every endpoint and downstream consumer (Employee detail card, balance detail's "Consuming Leave Requests" cross-link) uses uniformly.

**Why computed over stored** (slice plan Q1, locked):

- Drift is the worst class of bug for this kind of data — a stored cache that silently desyncs from the underlying truth is invisible to the user.
- Race conditions vanish. Concurrent approvals don't need row-level locking on a denormalized column.
- No `BalanceRecomputeListener` to fail, retry, or land in `failed_jobs`.
- Retroactive edits to LR dates / day_part / type, and soft-delete-then-restore, work for free.
- The SUM is sub-millisecond at SME scale (composite partial index `leave_requests_balance_lookup_idx` covers it).

If real-world scale ever forces a denormalized cache, `LeaveBalanceQueryService` is the one swap point.

### Allocated leave-type subset

`leave_balances.leave_type` is restricted to `('annual','sick')` via a DB CHECK constraint AND the `StoreLeaveRequestRequest` `Rule::in()`. The full `LeaveType` enum (`annual`, `sick`, `unpaid`, `other`) stays the source of truth for `leave_requests`, but **only `annual` and `sick` get balance rows**.

- `unpaid` is unbounded by definition — a balance row would be meaningless.
- `other` is treated as unbounded **in v1**. Promoting it to allocated later is additive (add to the CHECK + picker + seeder). Demoting later would be a cutover, so we lock the conservative state up front.

### When does an LR deduct? (Q2, locked)

| LR status | Balance impact |
| --- | --- |
| `pending` | None. Pending requests are informational; they don't reserve days. |
| `approved` | Deducts `days_count` from the `(employee, leave_type, EXTRACT(YEAR FROM start_date))` balance. |
| `rejected` | None. Rejected rows never enter the SUM. |
| Soft-deleted approved row | Auto-recovered. The SUM filters `WHERE deleted_at IS NULL`. |

There is **no balance enforcement in v1**. A manager CAN approve a 30-day request when only 12 days remain — the system shows `remaining_days: -18`, no block. Future slice. The Leave Request detail page footer mentions "balance impact: see Leave Balances" so a curious user knows where to look; no inline query.

### Period boundary (Q4, locked)

An LR spanning two years (Dec 28 → Jan 3) deducts **entirely from the year of `start_date`**. The aggregate uses `EXTRACT(YEAR FROM start_date)`. No splitting. The user takes 7 days starting in December → 2026's balance loses 7 days, 2027's balance is untouched.

### Half-day deduction (Q3, locked)

Mixed math falls out of `SUM(days_count)`:

- 3 full days + 1 morning + 1 afternoon = `3 + 0.5 + 0.5 = 4.0` consumed.

Because `days_count` is stored on `leave_requests` (see the Days count section under Leave Requests), the balance SUM is a clean aggregate — no `CASE WHEN day_part` duplication on the read side.

### Resource shape (full)

```json
{
    "data": {
        "id": 3,
        "employee": {
            "id": 42,
            "employee_code": "E-1001",
            "full_name": "Sokha Chan"
        },
        "leave_type": "annual",
        "period_year": 2026,
        "allocated_days": 14.0,
        "consumed_days": 3.0,
        "remaining_days": 11.0,
        "notes": "Standard annual allocation.",
        "consuming_leave_requests": [
            {
                "id": 17,
                "start_date": "2026-06-15",
                "end_date": "2026-06-17",
                "day_part": "full_day",
                "days_count": 3.0,
                "approved_at": "2026-05-24T14:30:00+00:00"
            }
        ],
        "created_at": "2026-05-26T10:00:00+00:00",
        "updated_at": "2026-05-26T10:00:00+00:00"
    }
}
```

`consuming_leave_requests` is populated only by the `show` endpoint — `store` and `update` responses return an empty array (the detail-page section is irrelevant in those flows; saving an extra subquery). The list contains the approved LRs that contributed to `consumed_days`, sorted DESC by `start_date`. Pending/rejected rows, sick rows when the balance is annual, prior-year rows, and soft-deleted rows are all excluded — the filter mirrors the SUM in `LeaveBalanceQueryService` exactly.

Over-consumption renders literally as a negative `remaining_days` (e.g. `-2.0`). The frontend labels it as "Over-consumed by N days" — the wire format preserves the sign, the UI gives it semantics.

### List shape (compact)

```json
{
    "data": [
        {
            "id": 3,
            "employee_id": 42,
            "employee_name": "Sokha Chan",
            "employee_code": "E-1001",
            "leave_type": "annual",
            "period_year": 2026,
            "allocated_days": 14.0,
            "consumed_days": 3.0,
            "remaining_days": 11.0
        }
    ],
    "meta": { "current_page": 1, "per_page": 25, "total": 12 }
}
```

### Endpoint: GET /api/v1/hrm/leave-balances

**Permission**: `hrm.leave_balance.view`

**Query parameters** (all optional):

| Param | Type | Notes |
| --- | --- | --- |
| `employee_id` | int | Scope to a single employee's balances. |
| `leave_type` | enum | `annual` or `sick` only. `unpaid`/`other` return 422. |
| `period_year` | int | Filter to a specific year (2000–2100). |
| `per_page` | int | 1–100, default 25. |

Default sort: `period_year DESC, employees.full_name ASC` (most-recent year first; alphabetical within a year).

### Endpoint: POST /api/v1/hrm/leave-balances

**Permission**: `hrm.leave_balance.create`

Validates:

- `employee_id` — scoped-exists in the current (tenant, company). Cross-context id returns 422 `errors.employee_id`.
- `leave_type` — `annual` or `sick`. Other values return 422 `errors.leave_type`.
- `period_year` — integer in `[2000, 2100]`.
- `allocated_days` — numeric, `>= 0`, `multiple_of:0.5`, max 366. Supports half-day granularity.
- `notes` — optional, max 500 chars.
- Unique tuple `(tenant_id, company_id, employee_id, leave_type, period_year)` enforced via `Rule::unique()` with partial `WHERE deleted_at IS NULL`. Re-creating after soft-delete is allowed (matches the discipline used elsewhere in HRM v1).

### Endpoint: PATCH /api/v1/hrm/leave-balances/{leaveBalance}

**Permission**: `hrm.leave_balance.update`

Editable fields: `allocated_days`, `notes`. The identity tuple (`employee_id`, `leave_type`, `period_year`) is **not** editable here — a user wanting to move a balance to a different employee/type/year creates a new row and deletes the old.

### Endpoint: DELETE /api/v1/hrm/leave-balances/{leaveBalance}

**Permission**: `hrm.leave_balance.delete`

Soft-deletes. Returns 204 on success, 404 on a row that's already soft-deleted (or doesn't exist, or is cross-context).

### Indexes

| Index | Purpose |
| --- | --- |
| `leave_balances_unique_employee_type_year` (partial unique, `WHERE deleted_at IS NULL`) | One balance per employee per type per year. |
| `leave_balances_tenant_company_employee_idx` | "All balances for employee X" queries. |
| `leave_balances_tenant_company_period_idx` | "All balances for year N" queries. |
| `leave_requests_balance_lookup_idx` (composite partial, `WHERE status='approved' AND deleted_at IS NULL`) | The SUM aggregate's covering index. Narrows the scan to approved + non-deleted rows; covers `(tenant, company, employee, type, start_date)` so `EXTRACT(YEAR FROM start_date)` GROUP BY is index-ordered. |

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

- Branch-related: multi-line addresses (street1/street2/state/postal_code), branch hierarchy (parent/child branches), branch manager FK, branch-level role scoping, operating hours, GPS coordinates / map integration, branch-level capacity
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
- Calendar / cross-team overlap views, accruals, hourly requests (the half-day case IS supported — see the Day-part subsection; basic Leave Balances ARE supported as of this slice — see the Leave Balances section above)
- Email/Slack notifications to the requester or manager
- Bulk approve/reject
- Leave Balance enforcement (v1 allows over-consumption — system shows the negative, no block. Enforcement is a future slice.)
- Automatic year-end rollover for leave balances (carry-over remaining days to next year)
- Pro-rated allocation for mid-year hires
- Leave accrual ("earn 1.25 days per month worked")
- Leave-type management UI (the enum is currently hard-coded)
- Balance adjustment audit-trail entries beyond the generic `audit_logs` coverage
- Inline balance-impact display on the Leave Request detail page (deferred — balance detail is one click away; adds a query for marginal value)
