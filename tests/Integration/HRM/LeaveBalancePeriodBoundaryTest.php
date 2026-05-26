<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────────
// Period boundary: a leave_request that spans Dec → Jan deducts ENTIRELY
// from the year of start_date — not split across years.
//
// Q4 of the slice plan, locked decision. Simplest rule, matches "I'm
// taking time off starting Dec 28."
//
// The test seeds both a 2026 AND a 2027 balance for the same employee +
// type, approves a Dec 28 2026 → Jan 3 2027 (7 calendar days) LR, then
// asserts:
//
//   • 2026 balance: consumed=7 (the entire span deducts from 2026)
//   • 2027 balance: consumed=0 (Jan portion NOT counted against 2027)
//
// EXTRACT(YEAR FROM start_date) in the LeaveBalanceQueryService is the
// load-bearing expression that produces this — change it and this test
// fails immediately rather than silently splitting balances.
// ─────────────────────────────────────────────────────────────────────────────

use App\Domain\HRM\Actions\ApproveLeaveRequestAction;
use App\Domain\HRM\Actions\CreateLeaveRequestAction;
use App\Domain\HRM\Enums\LeaveType;
use App\Domain\HRM\Models\Employee;
use App\Domain\HRM\Models\LeaveBalance;
use App\Domain\HRM\Services\LeaveBalanceQueryService;
use App\Models\Company;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Company\CompanyContext;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\Framework\DefaultPermissionsSeeder;
use Database\Seeders\Framework\DefaultRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed([DefaultPermissionsSeeder::class, DefaultRolesSeeder::class]);

    $this->tenant = Tenant::factory()->create();
    $this->company = Company::factory()->forTenant($this->tenant)->create();
    app(TenantContext::class)->setCurrent($this->tenant);
    app(CompanyContext::class)->setCurrent($this->company);

    $this->employee = Employee::factory()->forCompany($this->company)->create();
    $this->manager = User::factory()->forTenant($this->tenant)->create([
        'default_company_id' => $this->company->id,
        'current_company_id' => $this->company->id,
    ]);
    $this->manager->assignTenantRole($this->tenant, 'tenant_admin');
    $this->actingAs($this->manager);

    $this->balance2026 = LeaveBalance::factory()->forEmployee($this->employee)->annual()->create([
        'period_year' => 2026,
        'allocated_days' => 14.0,
    ]);
    $this->balance2027 = LeaveBalance::factory()->forEmployee($this->employee)->annual()->create([
        'period_year' => 2027,
        'allocated_days' => 14.0,
    ]);
});

it('LOAD-BEARING: a Dec→Jan LR deducts entirely from the year of start_date, not split', function (): void {
    // 2026-12-28 → 2027-01-03 = 7 calendar days inclusive.
    $lr = app(CreateLeaveRequestAction::class)->execute([
        'employee_id' => $this->employee->id,
        'leave_type' => LeaveType::Annual->value,
        'start_date' => '2026-12-28',
        'end_date' => '2027-01-03',
        'reason' => 'Year-end family time.',
    ]);
    expect((float) $lr->days_count)->toBe(7.0);

    app(ApproveLeaveRequestAction::class)->execute($lr, null, $this->manager->id);

    // 2026 balance ate the whole 7 days.
    $row2026 = app(LeaveBalanceQueryService::class)->query()
        ->where('leave_balances.id', $this->balance2026->id)
        ->firstOrFail();
    expect((float) $row2026->consumed_days)->toBe(7.0);
    expect((float) $row2026->remaining_days)->toBe(7.0);

    // 2027 balance is untouched.
    $row2027 = app(LeaveBalanceQueryService::class)->query()
        ->where('leave_balances.id', $this->balance2027->id)
        ->firstOrFail();
    expect((float) $row2027->consumed_days)->toBe(0.0);
    expect((float) $row2027->remaining_days)->toBe(14.0);
});
