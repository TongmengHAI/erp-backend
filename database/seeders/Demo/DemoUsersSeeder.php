<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Domain\HRM\Enums\AttendanceStatus;
use App\Domain\HRM\Enums\DepartmentStatus;
use App\Domain\HRM\Enums\EmployeeStatus;
use App\Domain\HRM\Enums\LeaveRequestStatus;
use App\Domain\HRM\Enums\LeaveType;
use App\Domain\HRM\Models\AttendanceRecord;
use App\Domain\HRM\Models\Department;
use App\Domain\HRM\Models\Employee;
use App\Domain\HRM\Models\LeaveRequest;
use App\Models\Company;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Company\Actions\BackfillUsersToCompanyAction;
use App\Support\Company\CompanyContext;
use App\Support\Company\Enums\CompanyStatus;
use App\Support\Tenancy\Enums\TenantStatus;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Demo users seeder — local dev only.
 *
 * Creates a deterministic minimal set of tenants, companies, and users so
 * the F3 integration smoke (and any later auth/permission debugging) has
 * data to exercise:
 *
 *   Tenant: Acme Trading Co. (active)
 *     └── Company: Acme Trading Co. (active)
 *           ├── admin@acme.test / password — tenant_admin role
 *           ├── 6 employees (mix of statuses: active / on_leave / terminated)
 *           ├── 4 departments (3 active: Operations, Finance, Sales;
 *           │                  1 archived: Warehouse)
 *           └── 5 of 6 employees assigned to departments (Operations × 2,
 *               Finance × 2, Sales × 1; Vichea on leave is unattached
 *               to exercise the nullable-department state)
 *
 *   Tenant: Suspended Co. (status=suspended)
 *     └── Company: Suspended Co. (active — the suspension is the tenant's)
 *           └── suspended@acme.test / password — no role
 *               (tenant_inactive path is exercised at /auth/me)
 *
 * NOT registered in DatabaseSeeder::run() — run explicitly with:
 *     php artisan db:seed --class="Database\Seeders\Demo\DemoUsersSeeder"
 *
 * Idempotent: re-running creates no duplicates. Tenants by slug, companies
 * by (tenant_id, slug), users by email. BackfillUsersToCompanyAction is
 * itself idempotent — it only fills null defaults.
 *
 * Depends on Framework\DefaultPermissionsSeeder + DefaultRolesSeeder having
 * been run first (they create the `tenant_admin` role this seeder uses).
 *
 * --- Company binding pattern ---
 *
 * Users are created FIRST with default_company_id=null and current_company_id=
 * null, then their Company is firstOrCreated, then BackfillUsersToCompanyAction
 * binds the user(s) to the company. This is the same code path a future
 * company-creation endpoint will use when an admin provisions a second
 * company for an existing tenant (CLAUDE.md §3 Approach A transition).
 * Running the action here in the seeder gives it a real caller and a real
 * test path beyond the focused unit tests.
 *
 * --- Other identity-source notes ---
 *
 * Future tenant-scoped writes (Employee, JournalEntry, etc.) MUST be wrapped
 * in TenantContext::asSystem() when run outside a request context. User,
 * Tenant, Company, audit_logs are identity-source models per CLAUDE.md §3 —
 * they don't need the wrapper.
 */
final class DemoUsersSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // Console context has no resolved tenant. Company is tenant-scoped
        // (BelongsToTenant), so any Company query — including firstOrCreate's
        // existence-check SELECT — triggers TenantScope and throws without a
        // wrapper. asSystem clears the scope for the duration. Tenant and
        // User are identity-source and don't need it; we wrap only the
        // run() body here for simplicity since Company queries are in scope.
        app(TenantContext::class)->asSystem(function (): void {
            $this->seedAll();
        });
    }

    private function seedAll(): void
    {
        // ─── Acme Trading Co. tenant + company + admin user ──────────────────
        $acmeTenant = Tenant::query()->firstOrCreate(
            ['slug' => 'acme'],
            [
                'name' => 'Acme Trading Co.',
                'legal_name' => 'Acme Trading Co., Ltd.',
                'country_code' => 'KH',
                'default_currency' => 'USD',
                'functional_currency' => 'USD',
                'timezone' => 'Asia/Phnom_Penh',
                'status' => TenantStatus::Active,
            ],
        );

        $admin = User::query()->firstOrCreate(
            ['email' => 'admin@acme.test'],
            [
                'name' => 'Admin User',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'tenant_id' => $acmeTenant->id,
                'current_tenant_id' => $acmeTenant->id,
                // default/current company intentionally null — they get
                // backfilled by BackfillUsersToCompanyAction below, which
                // gives the action a real caller in this seed path.
            ],
        );

        $acmeCompany = Company::query()->firstOrCreate(
            ['tenant_id' => $acmeTenant->id, 'slug' => 'acme-trading'],
            [
                'name' => 'Acme Trading Co.',
                'legal_name' => 'Acme Trading Co., Ltd.',
                'country_code' => 'KH',
                'default_currency' => 'USD',
                'functional_currency' => 'USD',
                'timezone' => 'Asia/Phnom_Penh',
                'status' => CompanyStatus::Active,
            ],
        );

        // Route through the action rather than setting default_company_id
        // inline. Idempotent — on re-run, the user already has the binding
        // and the action skips them.
        app(BackfillUsersToCompanyAction::class)->execute($acmeCompany);

        // Idempotent role assignment scoped to the Acme tenant. HasTenantRoles
        // sets Spatie's team_id for the call and restores it on exit.
        $admin->assignTenantRole($acmeTenant, 'tenant_admin');

        // ─── Demo employees in Acme Trading Co. ───────────────────────────────
        // Six employees with deterministic codes so re-runs don't duplicate.
        // Mix of statuses so the list page exercises StatusBadge + filter UI.
        // CompanyContext is set for the duration of the inserts because
        // Employee uses BelongsToCompany and would otherwise throw without it.
        app(CompanyContext::class)->setCurrent($acmeCompany);

        $demoEmployees = [
            ['code' => 'E-1001', 'name' => 'Sokha Chan',    'email' => 'sokha.chan@acme.test',  'title' => 'Operations Manager', 'hire' => '2022-03-15', 'status' => EmployeeStatus::Active],
            ['code' => 'E-1002', 'name' => 'Rithy Pich',    'email' => 'rithy.pich@acme.test',  'title' => 'Senior Accountant',  'hire' => '2021-09-01', 'status' => EmployeeStatus::Active],
            ['code' => 'E-1003', 'name' => 'Bopha Nuon',    'email' => 'bopha.nuon@acme.test',  'title' => 'Sales Lead',         'hire' => '2023-01-10', 'status' => EmployeeStatus::Active],
            ['code' => 'E-1004', 'name' => 'Vichea Sok',    'email' => null,                     'title' => 'Warehouse Clerk',    'hire' => '2024-06-20', 'status' => EmployeeStatus::OnLeave],
            ['code' => 'E-1005', 'name' => 'Channary Lim',  'email' => 'channary.lim@acme.test', 'title' => 'HR Coordinator',     'hire' => '2022-11-08', 'status' => EmployeeStatus::Active],
            ['code' => 'E-1006', 'name' => 'Dara Heng',     'email' => 'dara.heng@acme.test',    'title' => 'Junior Accountant',  'hire' => '2020-04-05', 'status' => EmployeeStatus::Terminated],
        ];

        foreach ($demoEmployees as $row) {
            Employee::query()->firstOrCreate(
                ['tenant_id' => $acmeTenant->id, 'company_id' => $acmeCompany->id, 'employee_code' => $row['code']],
                [
                    'full_name' => $row['name'],
                    'email' => $row['email'],
                    'job_title' => $row['title'],
                    'hire_date' => $row['hire'],
                    'status' => $row['status'],
                ],
            );
        }

        // ─── Demo departments in Acme Trading Co. ─────────────────────────────
        // Four departments — three active, one archived — so the list page's
        // StatusBadge + status filter both have something to render. CompanyContext
        // is still set from the employee seed above (required by BelongsToCompany).
        $demoDepartments = [
            ['code' => 'D-OPS',   'name' => 'Operations', 'description' => 'Day-to-day operations team.',         'status' => DepartmentStatus::Active],
            ['code' => 'D-FIN',   'name' => 'Finance',    'description' => 'Finance and accounting team.',        'status' => DepartmentStatus::Active],
            ['code' => 'D-SALES', 'name' => 'Sales',      'description' => 'Sales and customer success.',         'status' => DepartmentStatus::Active],
            ['code' => 'D-WHSE',  'name' => 'Warehouse',  'description' => 'Historical warehouse team (retired).', 'status' => DepartmentStatus::Archived],
        ];

        foreach ($demoDepartments as $row) {
            Department::query()->firstOrCreate(
                ['tenant_id' => $acmeTenant->id, 'company_id' => $acmeCompany->id, 'code' => $row['code']],
                [
                    'name' => $row['name'],
                    'description' => $row['description'],
                    'status' => $row['status'],
                ],
            );
        }

        // ─── Backfill: assign demo employees to demo departments ─────────────
        // Realistic spread: 5 employees attached, 1 unattached. Both nullable
        // states are visible in the UI. Counts per department vary so the
        // Department detail's "X employees" line shows >1, =1, and 0.
        //
        //   Operations (D-OPS): Sokha + Channary    → count=2
        //   Finance    (D-FIN): Rithy + Dara        → count=2
        //   Sales      (D-SALES): Bopha             → count=1
        //   Warehouse  (D-WHSE, archived): —        → count=0
        //   Vichea (on leave, formerly Warehouse): department_id=null
        //
        // Idempotent: re-running the seeder no-ops because the employee_id ↔
        // department_code pairs are stable across runs. Uses forceFill->save
        // rather than the action layer because seeding is not a user
        // operation and shouldn't write audit rows for assignments that
        // didn't happen at a user-meaningful moment.
        $departmentByCode = Department::query()
            ->where('tenant_id', $acmeTenant->id)
            ->where('company_id', $acmeCompany->id)
            ->pluck('id', 'code');

        $assignments = [
            'E-1001' => 'D-OPS',    // Sokha — Operations Manager
            'E-1002' => 'D-FIN',    // Rithy — Senior Accountant
            'E-1003' => 'D-SALES',  // Bopha — Sales Lead
            'E-1004' => null,       // Vichea — on leave, formerly Warehouse (archived)
            'E-1005' => 'D-OPS',    // Channary — HR Coordinator (rolls under Ops)
            'E-1006' => 'D-FIN',    // Dara — Junior Accountant (terminated, kept on record)
        ];

        foreach ($assignments as $empCode => $deptCode) {
            $employee = Employee::query()
                ->where('tenant_id', $acmeTenant->id)
                ->where('company_id', $acmeCompany->id)
                ->where('employee_code', $empCode)
                ->first();
            if ($employee === null) {
                continue;
            }
            $employee->forceFill([
                'department_id' => $deptCode !== null ? $departmentByCode[$deptCode] ?? null : null,
            ])->save();
        }

        // ─── manager@acme.test — workflow demo approver ──────────────────────
        // Separate user so the demo leave_requests show real workflow
        // attribution ("Approved by Manager User") rather than the surreal
        // "approved by yourself" if the same admin had decided their own
        // requests. Same tenant_admin role for simplicity — the role split
        // (.approve as a standalone "team_lead" role) is future work.
        $manager = User::query()->firstOrCreate(
            ['email' => 'manager@acme.test'],
            [
                'name' => 'Manager User',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'tenant_id' => $acmeTenant->id,
                'current_tenant_id' => $acmeTenant->id,
            ],
        );
        app(BackfillUsersToCompanyAction::class)->execute($acmeCompany);
        $manager->assignTenantRole($acmeTenant, 'tenant_admin');

        // ─── Demo leave_requests (5 rows: mix of states) ─────────────────────
        // Spread across statuses so the list page exercises the filter UI
        // and the badge rendering, and so the detail page demonstrates both
        // the pending and decided shapes (the decided-by panel).
        //
        // Why direct write of approval columns (not via Actions):
        // routing the approved/rejected rows through Approve/RejectAction
        // would generate audit_log entries time-stamped at seed-time with
        // actor=null (no auth context), misleading anyone reading the audit
        // history as "this row was approved by the system at install time."
        // The actual workflow attribution lives in the row itself
        // (approved_by + approved_at + approver_note), which is what the
        // UI reads. The audit log for these rows correctly shows only the
        // 'created' event from the firstOrCreate INSERT.
        //
        // Composite DB CHECK (status, approved_by, approved_at) is
        // satisfied because every approved/rejected row writes all three
        // columns in the same INSERT — that's the regression-protected
        // invariant the seeder/CHECK guard test asserts.
        $employeesByCode = Employee::query()
            ->where('tenant_id', $acmeTenant->id)
            ->where('company_id', $acmeCompany->id)
            ->get()
            ->keyBy('employee_code');

        $now = now();

        $demoLeaveRequests = [
            // 2 pending — fresh requests in the manager's inbox
            [
                'employee_code' => 'E-1001',
                'leave_type' => LeaveType::Annual,
                'start_date' => '2026-06-15',
                'end_date' => '2026-06-19',
                'reason' => 'Family wedding in Siem Reap.',
                'status' => LeaveRequestStatus::Pending,
                'approved_by' => null,
                'approved_at' => null,
                'approver_note' => null,
            ],
            [
                'employee_code' => 'E-1003',
                'leave_type' => LeaveType::Sick,
                'start_date' => '2026-05-28',
                'end_date' => '2026-05-29',
                'reason' => 'Flu — doctor recommended two days rest.',
                'status' => LeaveRequestStatus::Pending,
                'approved_by' => null,
                'approved_at' => null,
                'approver_note' => null,
            ],
            // 2 approved by Manager — historical decided rows
            [
                'employee_code' => 'E-1002',
                'leave_type' => LeaveType::Annual,
                'start_date' => '2026-04-10',
                'end_date' => '2026-04-14',
                'reason' => 'Khmer New Year extended break.',
                'status' => LeaveRequestStatus::Approved,
                'approved_by' => $manager->id,
                'approved_at' => $now->copy()->subDays(40),
                'approver_note' => 'Approved — coverage arranged with Dara.',
            ],
            [
                'employee_code' => 'E-1005',
                'leave_type' => LeaveType::Unpaid,
                'start_date' => '2026-03-01',
                'end_date' => '2026-03-05',
                'reason' => 'Personal travel.',
                'status' => LeaveRequestStatus::Approved,
                'approved_by' => $manager->id,
                'approved_at' => $now->copy()->subDays(85),
                'approver_note' => null,
            ],
            // 1 rejected by Manager
            [
                'employee_code' => 'E-1003',
                'leave_type' => LeaveType::Other,
                'start_date' => '2026-05-02',
                'end_date' => '2026-05-03',
                'reason' => 'Personal errand.',
                'status' => LeaveRequestStatus::Rejected,
                'approved_by' => $manager->id,
                'approved_at' => $now->copy()->subDays(20),
                'approver_note' => 'Two sales calls already booked that week — please reschedule.',
            ],
        ];

        foreach ($demoLeaveRequests as $row) {
            $employee = $employeesByCode[$row['employee_code']] ?? null;
            if ($employee === null) {
                continue;
            }
            // Idempotent on (tenant, company, employee, start_date, leave_type)
            // — re-runs find the existing row instead of duplicating.
            LeaveRequest::query()->firstOrCreate(
                [
                    'tenant_id' => $acmeTenant->id,
                    'company_id' => $acmeCompany->id,
                    'employee_id' => $employee->id,
                    'start_date' => $row['start_date'],
                    'leave_type' => $row['leave_type'],
                ],
                [
                    'end_date' => $row['end_date'],
                    'reason' => $row['reason'],
                    'status' => $row['status'],
                    'approved_by' => $row['approved_by'],
                    'approved_at' => $row['approved_at'],
                    'approver_note' => $row['approver_note'],
                ],
            );
        }

        // ─── Demo attendance_records (10 rows: mix of statuses) ─────────────
        // Two weeks of attendance for two demo employees (Sokha + Rithy)
        // so the list page has a date range to scroll, the status filter
        // has rows in every bucket, and the detail page has examples of
        // each enum value to inspect. Idempotent on (employee, date) via
        // firstOrCreate.
        //
        // The demo data deliberately includes ONE on_leave row and ONE
        // half_day row even though the Leave Requests coupling is
        // deferred (slice plan option a) — admins record those labels
        // manually today, the Leave Balances slice introduces derivation.
        $demoAttendanceRows = [
            // Sokha — full present week then varied
            ['emp_code' => 'E-1001', 'date' => '2026-05-12', 'in' => '09:00:00', 'out' => '18:00:00', 'status' => AttendanceStatus::Present,  'notes' => null],
            ['emp_code' => 'E-1001', 'date' => '2026-05-13', 'in' => '09:00:00', 'out' => '18:00:00', 'status' => AttendanceStatus::Present,  'notes' => null],
            ['emp_code' => 'E-1001', 'date' => '2026-05-14', 'in' => '09:45:00', 'out' => '18:00:00', 'status' => AttendanceStatus::Late,     'notes' => 'Train delay.'],
            ['emp_code' => 'E-1001', 'date' => '2026-05-15', 'in' => '09:00:00', 'out' => '13:00:00', 'status' => AttendanceStatus::HalfDay,  'notes' => 'Afternoon off — personal.'],
            ['emp_code' => 'E-1001', 'date' => '2026-05-18', 'in' => null,       'out' => null,       'status' => AttendanceStatus::OnLeave,  'notes' => 'Annual leave (no leave_request linked — manual entry).'],
            // Rithy — mix
            ['emp_code' => 'E-1002', 'date' => '2026-05-12', 'in' => '09:00:00', 'out' => '18:00:00', 'status' => AttendanceStatus::Present,  'notes' => null],
            ['emp_code' => 'E-1002', 'date' => '2026-05-13', 'in' => null,       'out' => null,       'status' => AttendanceStatus::Absent,   'notes' => 'No-show; flagged for follow-up.'],
            ['emp_code' => 'E-1002', 'date' => '2026-05-14', 'in' => '09:00:00', 'out' => '18:00:00', 'status' => AttendanceStatus::Present,  'notes' => null],
            ['emp_code' => 'E-1002', 'date' => '2026-05-15', 'in' => '09:00:00', 'out' => '18:00:00', 'status' => AttendanceStatus::Present,  'notes' => null],
            ['emp_code' => 'E-1002', 'date' => '2026-05-18', 'in' => '09:00:00', 'out' => '18:00:00', 'status' => AttendanceStatus::Present,  'notes' => null],
        ];

        foreach ($demoAttendanceRows as $row) {
            $employee = $employeesByCode[$row['emp_code']] ?? null;
            if ($employee === null) {
                continue;
            }
            AttendanceRecord::query()->firstOrCreate(
                [
                    'tenant_id' => $acmeTenant->id,
                    'company_id' => $acmeCompany->id,
                    'employee_id' => $employee->id,
                    'date' => $row['date'],
                ],
                [
                    'clock_in' => $row['in'],
                    'clock_out' => $row['out'],
                    'status' => $row['status'],
                    'notes' => $row['notes'],
                ],
            );
        }

        // Clear CompanyContext before moving on to the suspended-tenant block,
        // which has its own company context concerns handled inside asSystem.
        app(CompanyContext::class)->setCurrent(null);

        // ─── Suspended Co. tenant + company + suspended user ─────────────────
        $suspendedTenant = Tenant::query()->firstOrCreate(
            ['slug' => 'suspended-co'],
            [
                'name' => 'Suspended Co.',
                'legal_name' => 'Suspended Co., Ltd.',
                'country_code' => 'KH',
                'default_currency' => 'USD',
                'functional_currency' => 'USD',
                'timezone' => 'Asia/Phnom_Penh',
                'status' => TenantStatus::Suspended,
            ],
        );

        // Re-assert status on subsequent runs in case the row was tweaked
        // mid-debugging — keeps the seeder's promise truthful.
        if ($suspendedTenant->status !== TenantStatus::Suspended) {
            $suspendedTenant->forceFill(['status' => TenantStatus::Suspended])->save();
        }

        $suspendedUser = User::query()->firstOrCreate(
            ['email' => 'suspended@acme.test'],
            [
                'name' => 'Suspended User',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'tenant_id' => $suspendedTenant->id,
                'current_tenant_id' => $suspendedTenant->id,
            ],
        );

        $suspendedCompany = Company::query()->firstOrCreate(
            ['tenant_id' => $suspendedTenant->id, 'slug' => 'suspended-co-main'],
            [
                'name' => 'Suspended Co.',
                'legal_name' => 'Suspended Co., Ltd.',
                'country_code' => 'KH',
                'default_currency' => 'USD',
                'functional_currency' => 'USD',
                'timezone' => 'Asia/Phnom_Penh',
                'status' => CompanyStatus::Active,
            ],
        );

        // Bind the suspended user to the company even though the tenant is
        // suspended — the binding is structural; the suspension is
        // enforced at the tenant layer before company context matters.
        // ResolveTenant throws tenant_inactive before ResolveCompany ever
        // sees this user, so the user.default_company_id is dormant.
        app(BackfillUsersToCompanyAction::class)->execute($suspendedCompany);

        // No role assignment for the suspended user — the suspension path
        // is intercepted at /auth/me before permission resolution happens.
        unset($suspendedUser);

        $this->command->info(
            'DemoUsersSeeder: seeded admin@acme.test + manager@acme.test (Acme Trading Co., active) + suspended@acme.test (Suspended Co., suspended). 5 demo leave_requests + 10 demo attendance_records seeded (covering every status enum value).'
        );
    }
}
