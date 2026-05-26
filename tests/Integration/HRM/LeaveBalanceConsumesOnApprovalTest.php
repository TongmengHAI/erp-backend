<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────────
// THE load-bearing test for the computed-state pattern.
//
// Covers the full lifecycle of consumption + recovery against a single
// employee's annual balance for 2026:
//
//   1. Seed a balance row (14 allocated, 2026). consumed=0, remaining=14.
//   2. Create a PENDING LR (3 days, 2026). Pending doesn't deduct — balance
//      still shows 14 remaining. (Q2 of slice plan: pending = no impact.)
//   3. APPROVE the LR via the Action. Balance now shows consumed=3,
//      remaining=11.
//   4. Create a SECOND pending LR (5 days), REJECT it. Balance still
//      shows 11 — rejected rows never enter the SUM.
//   5. SOFT-DELETE the approved LR. Balance recovers automatically to
//      consumed=0, remaining=14 (the SUM's WHERE deleted_at IS NULL drops
//      the soft-deleted row).
//
// If ANY of these steps surface the wrong number, the design isn't
// correct yet — this test is the gate for the whole computed-state
// design decision (Option B from the slice plan). It crosses two
// modules' state (LeaveRequest writes, LeaveBalance reads) — exactly
// what the slice plan called out as the integration test.
// ─────────────────────────────────────────────────────────────────────────────

use App\Domain\HRM\Actions\ApproveLeaveRequestAction;
use App\Domain\HRM\Actions\CreateLeaveRequestAction;
use App\Domain\HRM\Actions\RejectLeaveRequestAction;
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

    $this->balance = LeaveBalance::factory()->forEmployee($this->employee)->annual()->create([
        'period_year' => 2026,
        'allocated_days' => 14.0,
    ]);
});

/**
 * Helper: fetch the computed remaining_days for our seeded balance.
 * Goes through the service so the assertion mirrors what the API
 * endpoint returns.
 */
function readBalance(int $balanceId): LeaveBalance
{
    return app(LeaveBalanceQueryService::class)->query()
        ->where('leave_balances.id', $balanceId)
        ->firstOrFail();
}

it('LOAD-BEARING: pending LR does NOT deduct; approved LR deducts; rejected LR does not; soft-deleting approved recovers', function (): void {
    // Step 1: fresh balance — consumed=0, remaining=14.
    $row = readBalance($this->balance->id);
    expect((float) $row->consumed_days)->toBe(0.0);
    expect((float) $row->remaining_days)->toBe(14.0);

    // Step 2: create a pending LR (3 days). No deduction.
    $pending = app(CreateLeaveRequestAction::class)->execute([
        'employee_id' => $this->employee->id,
        'leave_type' => LeaveType::Annual->value,
        'start_date' => '2026-06-15',
        'end_date' => '2026-06-17',
        'reason' => 'Pending — no balance impact yet.',
    ]);
    expect((float) $pending->days_count)->toBe(3.0);

    $row = readBalance($this->balance->id);
    expect((float) $row->consumed_days)->toBe(0.0)
        ->and((float) $row->remaining_days)->toBe(14.0);

    // Step 3: approve the LR. Now deducts.
    $approved = app(ApproveLeaveRequestAction::class)->execute(
        $pending,
        'Coverage arranged.',
        $this->manager->id,
    );
    expect($approved->status->value)->toBe('approved');

    $row = readBalance($this->balance->id);
    expect((float) $row->consumed_days)->toBe(3.0)
        ->and((float) $row->remaining_days)->toBe(11.0);

    // Step 4: create + reject a second 5-day LR. Balance unchanged.
    $second = app(CreateLeaveRequestAction::class)->execute([
        'employee_id' => $this->employee->id,
        'leave_type' => LeaveType::Annual->value,
        'start_date' => '2026-07-13',
        'end_date' => '2026-07-17',
        'reason' => 'Will be rejected.',
    ]);
    app(RejectLeaveRequestAction::class)->execute(
        $second,
        'Conflict with peak season.',
        $this->manager->id,
    );

    $row = readBalance($this->balance->id);
    expect((float) $row->consumed_days)->toBe(3.0)
        ->and((float) $row->remaining_days)->toBe(11.0);

    // Step 5: soft-delete the approved LR. Balance recovers — no listener,
    // no cache invalidation, no race condition. The SUM's WHERE deleted_at
    // IS NULL handles it implicitly.
    $approved->delete();

    $row = readBalance($this->balance->id);
    expect((float) $row->consumed_days)->toBe(0.0)
        ->and((float) $row->remaining_days)->toBe(14.0);
});

it('LOAD-BEARING: over-consumption returns a NEGATIVE remaining_days literally, not clamped to 0', function (): void {
    // Create + approve a 20-day LR against a 14-day allocation.
    // The system must report -6, not 0, not an error. The wire format
    // is the load-bearing contract — Session 2's UI labels it
    // ("Over-consumed by 6 days") off the wire's signed value.
    $lr = app(CreateLeaveRequestAction::class)->execute([
        'employee_id' => $this->employee->id,
        'leave_type' => LeaveType::Annual->value,
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-20',
        'reason' => 'Long trip.',
    ]);
    expect((float) $lr->days_count)->toBe(20.0);

    app(ApproveLeaveRequestAction::class)->execute($lr, null, $this->manager->id);

    $row = readBalance($this->balance->id);
    expect((float) $row->consumed_days)->toBe(20.0);
    expect((float) $row->remaining_days)->toBe(-6.0);
});

it('LOAD-BEARING: half-day LRs deduct 0.5 from the balance — mixed math works', function (): void {
    // Approve: 3 full days + 1 morning + 1 afternoon = 4.0 consumed.
    $three = app(CreateLeaveRequestAction::class)->execute([
        'employee_id' => $this->employee->id,
        'leave_type' => LeaveType::Annual->value,
        'start_date' => '2026-09-07',
        'end_date' => '2026-09-09',
        'reason' => '3 full days.',
    ]);
    $morning = app(CreateLeaveRequestAction::class)->execute([
        'employee_id' => $this->employee->id,
        'leave_type' => LeaveType::Annual->value,
        'start_date' => '2026-09-14',
        'end_date' => '2026-09-14',
        'day_part' => 'morning',
        'reason' => 'Morning off.',
    ]);
    $afternoon = app(CreateLeaveRequestAction::class)->execute([
        'employee_id' => $this->employee->id,
        'leave_type' => LeaveType::Annual->value,
        'start_date' => '2026-09-15',
        'end_date' => '2026-09-15',
        'day_part' => 'afternoon',
        'reason' => 'Afternoon off.',
    ]);

    app(ApproveLeaveRequestAction::class)->execute($three, null, $this->manager->id);
    app(ApproveLeaveRequestAction::class)->execute($morning, null, $this->manager->id);
    app(ApproveLeaveRequestAction::class)->execute($afternoon, null, $this->manager->id);

    $row = readBalance($this->balance->id);
    expect((float) $row->consumed_days)->toBe(4.0);
    expect((float) $row->remaining_days)->toBe(10.0);
});
