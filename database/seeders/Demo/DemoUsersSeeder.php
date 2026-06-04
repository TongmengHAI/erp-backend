<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Domain\HRM\Enums\AttendanceStatus;
use App\Domain\HRM\Enums\BranchStatus;
use App\Domain\HRM\Enums\DayPart;
use App\Domain\HRM\Enums\DepartmentStatus;
use App\Domain\HRM\Enums\EmployeeStatus;
use App\Domain\HRM\Enums\LeaveRequestStatus;
use App\Domain\HRM\Enums\LeaveType;
use App\Domain\HRM\Enums\PositionStatus;
use App\Domain\HRM\Models\AttendanceRecord;
use App\Domain\HRM\Models\Branch;
use App\Domain\HRM\Models\Department;
use App\Domain\HRM\Models\Employee;
use App\Domain\HRM\Models\LeaveBalance;
use App\Domain\HRM\Models\LeaveRequest;
use App\Domain\HRM\Models\Position;
use App\Domain\HRM\Support\LeaveDaysCalculator;
use App\Domain\Platform\Enums\ModuleStatus;
use App\Domain\Platform\Models\TenantModule;
use App\Models\Company;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Company\Actions\BackfillUsersToCompanyAction;
use App\Support\Company\CompanyContext;
use App\Support\Company\Enums\CompanyStatus;
use App\Support\Company\Events\CompanyCreated;
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
 *           ├── 7 positions (6 active matching the historical job_title
 *           │                values, 1 archived "Intern (Retired)")
 *           ├── 6 employees, every one linked to a position via position_id
 *           │                (the Positions slice replaced the old
 *           │                 free-text job_title field)
 *           ├── 4 departments (3 active: Operations, Finance, Sales;
 *           │                  1 archived: Warehouse)
 *           ├── 5 of 6 employees assigned to departments (Operations × 2,
 *           │   Finance × 2, Sales × 1; Vichea on leave is unattached
 *           │   to exercise the nullable-department state)
 *           ├── 4 branches (3 active: Phnom Penh HQ, Phnom Penh
 *           │                Warehouse, Sihanoukville Office; 1 archived:
 *           │                Legacy Retail). country_code='KH' uppercase
 *           │                consistently — matches the FormRequest regex.
 *           └── 5 of 6 employees assigned to branches (HQ × 3, Warehouse
 *               × 1, Sihanoukville × 1; Vichea on leave is unattached)
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

        // The seeder uses WithoutModelEvents to suppress Auditable noise
        // during the seed run — but that also suppresses the
        // CompanyCreated event the HrmSettings bootstrap listener
        // subscribes to. Dispatch manually on the just-created path so
        // the listener gets to run and create the default hrm_settings
        // row. Idempotent on re-seed via wasRecentlyCreated.
        if ($acmeCompany->wasRecentlyCreated) {
            CompanyCreated::dispatch($acmeCompany);
        }

        // Route through the action rather than setting default_company_id
        // inline. Idempotent — on re-run, the user already has the binding
        // and the action skips them.
        app(BackfillUsersToCompanyAction::class)->execute($acmeCompany);

        // Idempotent role assignment scoped to the Acme tenant. HasTenantRoles
        // sets Spatie's team_id for the call and restores it on exit.
        $admin->assignTenantRole($acmeTenant, 'tenant_admin');

        // HRM entitlement (Session 4 — closes the seeder-side §10.12 gap).
        // The migration backfill only covered tenants existing at migration
        // time; this tenant is created BY the seeder, so it needs an
        // explicit entitlement row.
        $this->ensureTenantHasHrmEntitlement($acmeTenant);

        // CompanyContext is required for any tenant-scoped writes below
        // (Position, Employee, Department, LeaveRequest, AttendanceRecord).
        app(CompanyContext::class)->setCurrent($acmeCompany);

        // ─── Demo positions in Acme Trading Co. ───────────────────────────────
        // Six positions matching the historical free-text job_title values
        // from before the Positions slice. Plus one archived position to
        // exercise the list page's status filter. Created BEFORE employees
        // so the employee seed below can resolve position_id by code.
        $demoPositions = [
            ['code' => 'P-OPS-MGR',  'title' => 'Operations Manager', 'description' => 'Heads day-to-day operations.',                 'status' => PositionStatus::Active],
            ['code' => 'P-FIN-SR',   'title' => 'Senior Accountant',  'description' => 'Senior finance role; closes books.',           'status' => PositionStatus::Active],
            ['code' => 'P-SAL-LEAD', 'title' => 'Sales Lead',         'description' => 'Leads the sales team.',                        'status' => PositionStatus::Active],
            ['code' => 'P-WH-CLERK', 'title' => 'Warehouse Clerk',    'description' => 'Inventory handling and dispatch.',             'status' => PositionStatus::Active],
            ['code' => 'P-HR-COORD', 'title' => 'HR Coordinator',     'description' => 'Coordinates HR programs and onboarding.',      'status' => PositionStatus::Active],
            ['code' => 'P-FIN-JR',   'title' => 'Junior Accountant',  'description' => 'Junior finance role; supports the seniors.',   'status' => PositionStatus::Active],
            ['code' => 'P-INTERN',   'title' => 'Intern (Retired)',   'description' => 'Historical intern role — list-filter exercise.', 'status' => PositionStatus::Archived],
        ];

        foreach ($demoPositions as $row) {
            Position::query()->firstOrCreate(
                ['tenant_id' => $acmeTenant->id, 'company_id' => $acmeCompany->id, 'code' => $row['code']],
                [
                    'title' => $row['title'],
                    'description' => $row['description'],
                    'status' => $row['status'],
                ],
            );
        }

        $positionByCode = Position::query()
            ->where('tenant_id', $acmeTenant->id)
            ->where('company_id', $acmeCompany->id)
            ->pluck('id', 'code');

        // ─── Demo employees in Acme Trading Co. ───────────────────────────────
        // Six employees with deterministic codes so re-runs don't duplicate.
        // position_code replaces the old free-text 'title' field (the
        // Positions slice cutover). Mix of statuses so the list page
        // exercises StatusBadge + filter UI.
        $demoEmployees = [
            ['code' => 'E-1001', 'name' => 'Sokha Chan',    'email' => 'sokha.chan@acme.test',  'position_code' => 'P-OPS-MGR',  'hire' => '2022-03-15', 'status' => EmployeeStatus::Active],
            ['code' => 'E-1002', 'name' => 'Rithy Pich',    'email' => 'rithy.pich@acme.test',  'position_code' => 'P-FIN-SR',   'hire' => '2021-09-01', 'status' => EmployeeStatus::Active],
            ['code' => 'E-1003', 'name' => 'Bopha Nuon',    'email' => 'bopha.nuon@acme.test',  'position_code' => 'P-SAL-LEAD', 'hire' => '2023-01-10', 'status' => EmployeeStatus::Active],
            ['code' => 'E-1004', 'name' => 'Vichea Sok',    'email' => null,                     'position_code' => 'P-WH-CLERK', 'hire' => '2024-06-20', 'status' => EmployeeStatus::OnLeave],
            ['code' => 'E-1005', 'name' => 'Channary Lim',  'email' => 'channary.lim@acme.test', 'position_code' => 'P-HR-COORD', 'hire' => '2022-11-08', 'status' => EmployeeStatus::Active],
            ['code' => 'E-1006', 'name' => 'Dara Heng',     'email' => 'dara.heng@acme.test',    'position_code' => 'P-FIN-JR',   'hire' => '2020-04-05', 'status' => EmployeeStatus::Terminated],
        ];

        foreach ($demoEmployees as $row) {
            Employee::query()->firstOrCreate(
                ['tenant_id' => $acmeTenant->id, 'company_id' => $acmeCompany->id, 'employee_code' => $row['code']],
                [
                    'full_name' => $row['name'],
                    'email' => $row['email'],
                    'position_id' => $positionByCode[$row['position_code']] ?? null,
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

        // ─── Demo branches in Acme Trading Co. ────────────────────────────────
        // Four branches — three active in Cambodia (Phnom Penh HQ, Phnom
        // Penh Warehouse, Sihanoukville Office) + one archived (Legacy
        // Retail). country_code is 'KH' uppercase consistently —
        // matches the StoreBranchRequest's ^[A-Z]{2}$ regex. The seeder
        // is the only ingestion path that bypasses the FormRequest
        // (Eloquent fillable + forceFill skip validation), so this
        // consistency is load-bearing for the v1 invariant that all
        // country_code values are valid ISO 3166-1 alpha-2.
        $demoBranches = [
            [
                'code' => 'B-PNH-HQ',
                'name' => 'Phnom Penh HQ',
                'description' => 'Main headquarters and executive offices.',
                'address' => 'Building 5, Street 240, Sangkat Boeung Raing, Khan Daun Penh',
                'city' => 'Phnom Penh',
                'country_code' => 'KH',
                'phone' => '+855 23 123 456',
                'status' => BranchStatus::Active,
            ],
            [
                'code' => 'B-PNH-WHSE',
                'name' => 'Phnom Penh Warehouse',
                'description' => 'Distribution warehouse in north Phnom Penh.',
                'address' => 'National Road 5, Russey Keo',
                'city' => 'Phnom Penh',
                'country_code' => 'KH',
                'phone' => '+855 23 987 654',
                'status' => BranchStatus::Active,
            ],
            [
                'code' => 'B-SHV-OFF',
                'name' => 'Sihanoukville Office',
                'description' => 'Regional sales office serving the south coast.',
                'address' => 'Street 219, Sangkat 4',
                'city' => 'Sihanoukville',
                'country_code' => 'KH',
                'phone' => '+855 34 555 1234',
                'status' => BranchStatus::Active,
            ],
            [
                'code' => 'B-OLD-RETAIL',
                'name' => 'Legacy Retail (Retired)',
                'description' => 'Closed retail front retained for historical attribution.',
                'address' => null,
                'city' => null,
                'country_code' => null,
                'phone' => null,
                'status' => BranchStatus::Archived,
            ],
        ];

        foreach ($demoBranches as $row) {
            Branch::query()->firstOrCreate(
                ['tenant_id' => $acmeTenant->id, 'company_id' => $acmeCompany->id, 'code' => $row['code']],
                [
                    'name' => $row['name'],
                    'description' => $row['description'],
                    'address' => $row['address'],
                    'city' => $row['city'],
                    'country_code' => $row['country_code'],
                    'phone' => $row['phone'],
                    'status' => $row['status'],
                ],
            );
        }

        // ─── Backfill: assign demo employees to demo branches ─────────────────
        // Realistic spread per the plan: 4 → HQ (most employees work from
        // HQ), 1 → SHV-OFF (Bopha — sales, naturally at a branch office),
        // 1 → null (Vichea on leave — exercises the unassigned state),
        // 1 → PNH-WHSE (Dara — terminated finance role that supported
        // warehouse operations).
        //
        //   PNH-HQ:     Sokha + Rithy + Channary + Bopha (wait, Bopha is SHV-OFF)
        //   PNH-HQ:     Sokha + Rithy + Channary                  → count=3
        //   PNH-WHSE:   Dara                                       → count=1
        //   SHV-OFF:    Bopha                                      → count=1
        //   Archived:   —                                          → count=0
        //   Vichea (on leave): branch_id=null
        $branchByCode = Branch::query()
            ->where('tenant_id', $acmeTenant->id)
            ->where('company_id', $acmeCompany->id)
            ->pluck('id', 'code');

        $branchAssignments = [
            'E-1001' => 'B-PNH-HQ',     // Sokha — Operations Manager (HQ)
            'E-1002' => 'B-PNH-HQ',     // Rithy — Senior Accountant (HQ)
            'E-1003' => 'B-SHV-OFF',    // Bopha — Sales Lead (regional office)
            'E-1004' => null,           // Vichea — on leave, unassigned
            'E-1005' => 'B-PNH-HQ',     // Channary — HR Coordinator (HQ)
            'E-1006' => 'B-PNH-WHSE',   // Dara — Junior Accountant (warehouse finance)
        ];

        foreach ($branchAssignments as $empCode => $branchCode) {
            $employee = Employee::query()
                ->where('tenant_id', $acmeTenant->id)
                ->where('company_id', $acmeCompany->id)
                ->where('employee_code', $empCode)
                ->first();
            if ($employee === null) {
                continue;
            }
            $employee->forceFill([
                'branch_id' => $branchCode !== null ? $branchByCode[$branchCode] ?? null : null,
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
            // ── Historical approved LRs that drive Leave Balance
            //    consumption. Tuned to land the demo balance table in
            //    a state that exercises the full UX:
            //
            //      • E-1001 annual: 3 consumed   → +11 remaining (healthy)
            //      • E-1002 annual: 10 consumed  →  0 remaining (zero edge)
            //      • E-1002 sick:    2.5 consumed → +4.5 remaining (half-day math)
            //      • E-1003 annual:  9 consumed  → -2 remaining (over-consumed)
            //      • E-1004 annual:  0.5 consumed → +13.5 remaining (lone half-day)
            //
            //    Total: 6 extra approved rows. Idempotent on
            //    (employee, start_date, leave_type) via firstOrCreate.
            //    days_count routes through LeaveDaysCalculator.
            [
                'employee_code' => 'E-1001',
                'leave_type' => LeaveType::Annual,
                'start_date' => '2026-02-09',
                'end_date' => '2026-02-11',
                'day_part' => DayPart::FullDay,
                'reason' => 'Family visit.',
                'status' => LeaveRequestStatus::Approved,
                'approved_by' => $manager->id,
                'approved_at' => $now->copy()->subDays(110),
                'approver_note' => null,
            ],
            [
                'employee_code' => 'E-1002',
                'leave_type' => LeaveType::Annual,
                'start_date' => '2026-05-18',
                'end_date' => '2026-05-22',
                'day_part' => DayPart::FullDay,
                'reason' => 'Annual leave — second half of allocation.',
                'status' => LeaveRequestStatus::Approved,
                'approved_by' => $manager->id,
                'approved_at' => $now->copy()->subDays(15),
                'approver_note' => null,
            ],
            [
                'employee_code' => 'E-1002',
                'leave_type' => LeaveType::Sick,
                'start_date' => '2026-04-22',
                'end_date' => '2026-04-23',
                'day_part' => DayPart::FullDay,
                'reason' => 'Stomach bug.',
                'status' => LeaveRequestStatus::Approved,
                'approved_by' => $manager->id,
                'approved_at' => $now->copy()->subDays(35),
                'approver_note' => null,
            ],
            [
                'employee_code' => 'E-1002',
                'leave_type' => LeaveType::Sick,
                'start_date' => '2026-05-06',
                'end_date' => '2026-05-06',
                'day_part' => DayPart::Morning,
                'reason' => 'Doctor appointment.',
                'status' => LeaveRequestStatus::Approved,
                'approved_by' => $manager->id,
                'approved_at' => $now->copy()->subDays(22),
                'approver_note' => null,
            ],
            [
                'employee_code' => 'E-1003',
                'leave_type' => LeaveType::Annual,
                'start_date' => '2026-03-16',
                'end_date' => '2026-03-24',
                'day_part' => DayPart::FullDay,
                'reason' => 'Extended Khmer New Year and family trip.',
                'status' => LeaveRequestStatus::Approved,
                'approved_by' => $manager->id,
                'approved_at' => $now->copy()->subDays(75),
                'approver_note' => 'Approved despite over-allocation — see HR for reconciliation.',
            ],
            [
                'employee_code' => 'E-1004',
                'leave_type' => LeaveType::Annual,
                'start_date' => '2026-05-19',
                'end_date' => '2026-05-19',
                'day_part' => DayPart::Morning,
                'reason' => 'School pickup.',
                'status' => LeaveRequestStatus::Approved,
                'approved_by' => $manager->id,
                'approved_at' => $now->copy()->subDays(8),
                'approver_note' => null,
            ],
        ];

        $daysCalculator = new LeaveDaysCalculator;

        foreach ($demoLeaveRequests as $row) {
            $employee = $employeesByCode[$row['employee_code']] ?? null;
            if ($employee === null) {
                continue;
            }
            // day_part defaults to FullDay if not pinned on the row;
            // half-day rows explicitly set Morning/Afternoon. days_count
            // routes through LeaveDaysCalculator so the seeder produces
            // the same value the production Action would.
            $dayPart = $row['day_part'] ?? DayPart::FullDay;

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
                    'day_part' => $dayPart,
                    'days_count' => $daysCalculator->compute(
                        $row['start_date'],
                        $row['end_date'],
                        $dayPart,
                    ),
                    'reason' => $row['reason'],
                    'status' => $row['status'],
                    'approved_by' => $row['approved_by'],
                    'approved_at' => $row['approved_at'],
                    'approver_note' => $row['approver_note'],
                ],
            );
        }

        // ─── Demo leave_balances (12 rows: 6 employees × annual+sick) ───────
        // Locks the period_year 2026 balance state for the demo tenant.
        // remaining_days is NOT stored — see LeaveBalanceQueryService for
        // the LEFT JOIN that computes it from the approved LRs above.
        // Allocations chosen with the LRs to land:
        //
        //   E-1001 annual: 14 allocated  -  3 consumed = +11 healthy
        //   E-1001 sick:    7 allocated  -  0 consumed = +7  fresh
        //   E-1002 annual: 10 allocated  - 10 consumed =  0  exact-zero edge
        //   E-1002 sick:    7 allocated  - 2.5 consumed = +4.5 half-day math
        //   E-1003 annual:  7 allocated  -  9 consumed = -2  OVER-CONSUMED
        //   E-1003 sick:    7 allocated  -  0 consumed = +7  unaffected sibling
        //   E-1004 annual: 14 allocated  - 0.5 consumed = +13.5 lone half-day
        //   E-1004 sick:    7 allocated  -  0 consumed = +7
        //   E-1005 annual: 14 allocated  -  0 consumed = +14 (unpaid LR doesn't count)
        //   E-1005 sick:    7 allocated  -  0 consumed = +7
        //   E-1006 annual: 14 allocated  -  0 consumed = +14
        //   E-1006 sick:    7 allocated  -  0 consumed = +7
        //
        // Idempotent on (tenant, company, employee, leave_type, period_year)
        // via firstOrCreate.
        $demoLeaveBalances = [
            ['employee_code' => 'E-1001', 'leave_type' => LeaveType::Annual, 'allocated_days' => 14.0, 'notes' => null],
            ['employee_code' => 'E-1001', 'leave_type' => LeaveType::Sick,   'allocated_days' => 7.0,  'notes' => null],
            ['employee_code' => 'E-1002', 'leave_type' => LeaveType::Annual, 'allocated_days' => 10.0, 'notes' => 'Reduced annual allocation per contract amendment.'],
            ['employee_code' => 'E-1002', 'leave_type' => LeaveType::Sick,   'allocated_days' => 7.0,  'notes' => null],
            ['employee_code' => 'E-1003', 'leave_type' => LeaveType::Annual, 'allocated_days' => 7.0,  'notes' => 'Probationary first-year allocation.'],
            ['employee_code' => 'E-1003', 'leave_type' => LeaveType::Sick,   'allocated_days' => 7.0,  'notes' => null],
            ['employee_code' => 'E-1004', 'leave_type' => LeaveType::Annual, 'allocated_days' => 14.0, 'notes' => null],
            ['employee_code' => 'E-1004', 'leave_type' => LeaveType::Sick,   'allocated_days' => 7.0,  'notes' => null],
            ['employee_code' => 'E-1005', 'leave_type' => LeaveType::Annual, 'allocated_days' => 14.0, 'notes' => null],
            ['employee_code' => 'E-1005', 'leave_type' => LeaveType::Sick,   'allocated_days' => 7.0,  'notes' => null],
            ['employee_code' => 'E-1006', 'leave_type' => LeaveType::Annual, 'allocated_days' => 14.0, 'notes' => null],
            ['employee_code' => 'E-1006', 'leave_type' => LeaveType::Sick,   'allocated_days' => 7.0,  'notes' => null],
        ];

        foreach ($demoLeaveBalances as $row) {
            $employee = $employeesByCode[$row['employee_code']] ?? null;
            if ($employee === null) {
                continue;
            }
            LeaveBalance::query()->firstOrCreate(
                [
                    'tenant_id' => $acmeTenant->id,
                    'company_id' => $acmeCompany->id,
                    'employee_id' => $employee->id,
                    'leave_type' => $row['leave_type'],
                    'period_year' => 2026,
                ],
                [
                    'allocated_days' => $row['allocated_days'],
                    'notes' => $row['notes'],
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

        // Clear CompanyContext before moving on to the next-tenant blocks
        // (Sokha + Suspended); each handles its own context concerns.
        app(CompanyContext::class)->setCurrent(null);

        // ─── Sokha Trading Co. tenant + company + 3 users (Session 4) ────────
        // Second active demo tenant. Exists so the SA dashboard renders a
        // multi-tenant state out of the box: cross-tenant bypass is
        // demonstrable (SA sees both Acme and Sokha tenants in one query),
        // and the entitled_modules / tenant_modules state for two
        // independent tenants is visible from the Tenants list. 3 users
        // chosen to exercise more than one role tier (tenant_admin + viewer)
        // — same coverage as Acme's admin/manager split plus a viewer who
        // has read-only HRM perms only.
        $sokhaTenant = Tenant::query()->firstOrCreate(
            ['slug' => 'sokha'],
            [
                'name' => 'Sokha Trading Co.',
                'legal_name' => 'Sokha Trading Co., Ltd.',
                'country_code' => 'KH',
                'default_currency' => 'USD',
                'functional_currency' => 'USD',
                'timezone' => 'Asia/Phnom_Penh',
                'status' => TenantStatus::Active,
            ],
        );

        $sokhaAdmin = User::query()->firstOrCreate(
            ['email' => 'admin@sokha.test'],
            [
                'name' => 'Sokha Admin',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'tenant_id' => $sokhaTenant->id,
                'current_tenant_id' => $sokhaTenant->id,
            ],
        );

        $sokhaManager = User::query()->firstOrCreate(
            ['email' => 'manager@sokha.test'],
            [
                'name' => 'Sokha Manager',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'tenant_id' => $sokhaTenant->id,
                'current_tenant_id' => $sokhaTenant->id,
            ],
        );

        $sokhaViewer = User::query()->firstOrCreate(
            ['email' => 'viewer@sokha.test'],
            [
                'name' => 'Sokha Viewer',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'tenant_id' => $sokhaTenant->id,
                'current_tenant_id' => $sokhaTenant->id,
            ],
        );

        $sokhaCompany = Company::query()->firstOrCreate(
            ['tenant_id' => $sokhaTenant->id, 'slug' => 'sokha-trading-main'],
            [
                'name' => 'Sokha Trading Main',
                'legal_name' => 'Sokha Trading Co., Ltd.',
                'country_code' => 'KH',
                'default_currency' => 'USD',
                'functional_currency' => 'USD',
                'timezone' => 'Asia/Phnom_Penh',
                'status' => CompanyStatus::Active,
            ],
        );

        // §10.12 trap — same as Acme + Suspended. WithoutModelEvents
        // would otherwise skip the listener; explicit dispatch closes
        // the gap so hrm_settings materialises for Sokha's company.
        if ($sokhaCompany->wasRecentlyCreated) {
            CompanyCreated::dispatch($sokhaCompany);
        }

        // Bind each Sokha user to the company.
        app(BackfillUsersToCompanyAction::class)->execute($sokhaCompany);

        // Role assignments — two tenant_admins + one viewer, matching
        // the Acme admin/manager pattern + adding a third role tier
        // to make the demo more interesting. Multi-tenant role-scoping
        // is the proof: the same role names exist in both tenants
        // with independent Spatie team_id scope.
        $sokhaAdmin->assignTenantRole($sokhaTenant, 'tenant_admin');
        $sokhaManager->assignTenantRole($sokhaTenant, 'tenant_admin');
        $sokhaViewer->assignTenantRole($sokhaTenant, 'viewer');

        $this->ensureTenantHasHrmEntitlement($sokhaTenant);

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

        // Same CompanyCreated dispatch as Acme — the seeder's
        // WithoutModelEvents trait would otherwise skip the listener.
        if ($suspendedCompany->wasRecentlyCreated) {
            CompanyCreated::dispatch($suspendedCompany);
        }

        // Bind the suspended user to the company even though the tenant is
        // suspended — the binding is structural; the suspension is
        // enforced at the tenant layer before company context matters.
        // ResolveTenant throws tenant_inactive before ResolveCompany ever
        // sees this user, so the user.default_company_id is dormant.
        app(BackfillUsersToCompanyAction::class)->execute($suspendedCompany);

        // Assign tenant_admin so the user has working permissions once
        // the SA resumes the tenant. The suspension gate at /auth/me
        // fires BEFORE permission resolution, so this assignment does
        // not weaken the suspended-path test (login → /auth/me 401
        // tenant_inactive still holds). The role only matters AFTER a
        // resume — without it, a resumed user lands on "No accessible
        // apps" because accessibleApps' permission-prefix filter rejects
        // every app for a roleless user. Mirrors admin@acme.test's
        // role; scoped to this tenant only.
        $suspendedUser->assignTenantRole($suspendedTenant, 'tenant_admin');

        // HRM entitlement for the suspended tenant — makes "Tenants per
        // module" dashboard tile count the full estate (suspended tenants
        // are still entitled; suspension is enforced at /auth/me before
        // the module gate ever fires).
        $this->ensureTenantHasHrmEntitlement($suspendedTenant);

        $this->command->info(
            'DemoUsersSeeder: seeded admin@acme.test + manager@acme.test (Acme Trading Co., active) + admin@sokha.test + manager@sokha.test + viewer@sokha.test (Sokha Trading Co., active) + suspended@acme.test (Suspended Co., suspended). 7 demo positions + 4 demo branches + 6 demo employees linked by department/position/branch_id + 11 demo leave_requests (5 lifecycle + 6 approved historical) + 12 demo leave_balances (annual+sick × 6 employees for 2026, including over-consumed/exact-zero/half-day cases) + 10 demo attendance_records. All three tenants have an Active HRM tenant_modules row.'
        );
    }

    /**
     * Idempotent helper: ensure the tenant has an Active HRM
     * tenant_modules row. Closes the §10.12-style gap in the seeder —
     * the migration backfill only covers tenants existing at migration
     * time, and `firstOrCreate` on a Tenant model (as opposed to
     * `Tenant::factory()->create()`) doesn't fire TenantFactory::
     * configure()'s afterCreating hook. Without this helper, demo
     * tenants are created but lack entitlement, so admins land on the
     * HRM module and 403 module_not_entitled.
     *
     * enabled_by_user_id is intentionally NULL (matches the migration
     * backfill's "system bootstrap, no actor" semantics for non-UI-
     * driven entitlement grants).
     */
    private function ensureTenantHasHrmEntitlement(Tenant $tenant): void
    {
        TenantModule::query()
            ->withoutGlobalScopes()
            ->firstOrCreate(
                ['tenant_id' => $tenant->id, 'module_key' => 'hrm'],
                [
                    'status' => ModuleStatus::Active,
                    'enabled_at' => now(),
                    'enabled_by_user_id' => null,
                ],
            );
    }
}
