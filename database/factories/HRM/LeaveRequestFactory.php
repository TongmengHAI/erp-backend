<?php

declare(strict_types=1);

namespace Database\Factories\HRM;

use App\Domain\HRM\Enums\DayPart;
use App\Domain\HRM\Enums\LeaveRequestStatus;
use App\Domain\HRM\Enums\LeaveType;
use App\Domain\HRM\Models\Employee;
use App\Domain\HRM\Models\LeaveRequest;
use App\Domain\HRM\Support\LeaveDaysCalculator;
use App\Models\Company;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use InvalidArgumentException;

/**
 * @extends Factory<LeaveRequest>
 */
class LeaveRequestFactory extends Factory
{
    protected $model = LeaveRequest::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $start = $this->faker->dateTimeBetween('-1 month', '+1 month');
        $end = (clone $start)->modify('+'.$this->faker->numberBetween(0, 7).' days');

        return [
            // Auto-builds a tenant + company + employee if not pinned via
            // forEmployee(). Each lazy factory honors the parent context
            // wiring (CompanyContext set in test/seeder).
            'tenant_id' => Tenant::factory(),
            'company_id' => Company::factory(),
            'employee_id' => Employee::factory(),
            'leave_type' => $this->faker->randomElement(LeaveType::cases()),
            'start_date' => $start->format('Y-m-d'),
            'end_date' => $end->format('Y-m-d'),
            // Default to full_day so the existing test fixtures continue
            // to behave unchanged. The halfDay() state below pins the
            // start_date==end_date invariant when callers opt in.
            'day_part' => DayPart::FullDay,
            // days_count is computed in configure()'s afterMaking() hook
            // so it ALWAYS reflects the row's final dates + day_part —
            // even when a caller overrides dates via ->create([...]).
            // Setting it here in definition() would freeze it to the
            // random definition-time dates, and any override would leave
            // a stale value. See the equivalence test for the discipline.
            'reason' => $this->faker->optional(0.7)->sentence(8),
            'status' => LeaveRequestStatus::Pending,
            // Pending rows have null approval columns; the composite DB
            // CHECK enforces this. The approved() / rejected() states
            // below also set the matching columns.
            'approved_by' => null,
            'approved_at' => null,
            'approver_note' => null,
        ];
    }

    /**
     * Recompute days_count after every ->make() / ->create() override
     * has landed. Factory's afterMaking fires AFTER the create-args
     * are merged in, so dates passed via ->create(['start_date' => ...,
     * 'end_date' => ...]) are visible here and the calculator gets the
     * final values. halfDay() pre-pins days_count = 0.5 in its own
     * afterMaking and only runs if the caller invoked it — when both
     * fire, the order is registration order; halfDay() chains AFTER
     * configure() so its 0.5 wins (which is the correct half-day value
     * regardless of the dates we'd compute here anyway).
     */
    public function configure(): static
    {
        return $this->afterMaking(function (LeaveRequest $request): void {
            $request->days_count = (new LeaveDaysCalculator)->compute(
                $request->start_date,
                $request->end_date,
                $request->day_part,
            );
        });
    }

    /**
     * Anchor this request to an existing employee (and their tenant +
     * company). Required when seeding multiple requests for a known
     * employee, or in tests that need a specific FK.
     */
    public function forEmployee(Employee $employee): static
    {
        return $this->state(fn (): array => [
            'tenant_id' => $employee->tenant_id,
            'company_id' => $employee->company_id,
            'employee_id' => $employee->id,
        ]);
    }

    /**
     * Approved state — sets all three approval columns together so the
     * composite (status, approved_by, approved_at) CHECK is satisfied.
     * Caller passes the approver User explicitly; defaulting it to a
     * factory user would create a tenant-detached user, which violates
     * the production invariant that approvers are real session-holding
     * users.
     */
    public function approved(User $approver, ?string $note = null): static
    {
        return $this->state(fn (): array => [
            'status' => LeaveRequestStatus::Approved,
            'approved_by' => $approver->id,
            'approved_at' => now(),
            'approver_note' => $note,
        ]);
    }

    public function rejected(User $approver, ?string $note = null): static
    {
        return $this->state(fn (): array => [
            'status' => LeaveRequestStatus::Rejected,
            'approved_by' => $approver->id,
            'approved_at' => now(),
            'approver_note' => $note,
        ]);
    }

    /**
     * Half-day state — sets day_part to Morning or Afternoon AND forces
     * start_date == end_date so the composite DB CHECK
     * (leave_requests_day_part_single_date_check) is satisfied.
     *
     * Uses afterMaking() rather than state() to sync end_date so the
     * sync runs AFTER any ->create(['start_date' => '...']) override.
     * If we used state(), the closure would run between definition()
     * and the create() override — we'd sync end_date to the random
     * definition start_date, then the explicit start_date override
     * would land and leave end_date stale, violating the CHECK.
     */
    public function halfDay(DayPart $part): static
    {
        if ($part === DayPart::FullDay) {
            // Defensive: full_day belongs in default state, not halfDay().
            throw new InvalidArgumentException(
                'halfDay() requires Morning or Afternoon; pass nothing for FullDay (default).',
            );
        }

        return $this
            ->state(['day_part' => $part, 'days_count' => 0.5])
            ->afterMaking(function (LeaveRequest $request): void {
                $request->end_date = $request->start_date;
                // Half-day = 0.5 by definition (calculator's contract).
                // Restating it explicitly here matches the state() above
                // so callers reading the factory see the value next to
                // the day_part it lives with.
                $request->days_count = 0.5;
            });
    }
}
