<?php

declare(strict_types=1);

namespace Database\Factories\HRM;

use App\Domain\HRM\Enums\AttendanceStatus;
use App\Domain\HRM\Models\AttendanceRecord;
use App\Domain\HRM\Models\Employee;
use App\Models\Company;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendanceRecord>
 */
class AttendanceRecordFactory extends Factory
{
    protected $model = AttendanceRecord::class;

    /**
     * Process-wide counter used by forEmployee() to stagger generated
     * `date` values. Each invocation steps the counter one day back —
     * eliminating the unique-index collisions that the prior random-
     * date-in-30-day-window default produced when tests called
     * forEmployee()->create() multiple times for the same employee.
     */
    private static int $dateCounter = 0;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        // Default: a present record in the recent past, typical 9-to-6
        // workday clock times. Other state helpers (absent, late, etc.)
        // override the relevant columns.
        return [
            'tenant_id' => Tenant::factory(),
            'company_id' => Company::factory(),
            'employee_id' => Employee::factory(),
            'date' => $this->faker->dateTimeBetween('-30 days', '-1 day')->format('Y-m-d'),
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
            'status' => AttendanceStatus::Present,
            'notes' => null,
        ];
    }

    /**
     * Anchor this record to an existing employee (and their tenant +
     * company). Required when seeding multiple records for a known
     * employee, or in tests that need a specific FK.
     *
     * Date staggering: each call walks the process-wide $dateCounter
     * back one more day. This keeps every generated `date` distinct
     * across the test process, so tests that call forEmployee() many
     * times for the same employee cannot collide on the (tenant_id,
     * company_id, employee_id, date) unique index. Callers can still
     * override `date` via ->create(['date' => ...]); create-args win
     * over state.
     */
    public function forEmployee(Employee $employee): static
    {
        return $this->state(function () use ($employee): array {
            self::$dateCounter++;

            return [
                'tenant_id' => $employee->tenant_id,
                'company_id' => $employee->company_id,
                'employee_id' => $employee->id,
                'date' => now()->subDays(self::$dateCounter)->format('Y-m-d'),
            ];
        });
    }

    /**
     * Absent state — clears both clock times so the row reflects a
     * day off entirely. Status-vs-clock cross-validation isn't enforced
     * at the DB layer (the clock_order CHECK only fires when BOTH
     * times are non-null), but the conventional shape is "no clocks
     * for an absent day."
     */
    public function absent(): static
    {
        return $this->state(fn (): array => [
            'status' => AttendanceStatus::Absent,
            'clock_in' => null,
            'clock_out' => null,
        ]);
    }

    public function late(): static
    {
        return $this->state(fn (): array => [
            'status' => AttendanceStatus::Late,
            'clock_in' => '09:45:00',
            'clock_out' => '18:00:00',
        ]);
    }

    public function onLeave(): static
    {
        return $this->state(fn (): array => [
            'status' => AttendanceStatus::OnLeave,
            'clock_in' => null,
            'clock_out' => null,
        ]);
    }

    public function halfDay(): static
    {
        return $this->state(fn (): array => [
            'status' => AttendanceStatus::HalfDay,
            'clock_in' => '09:00:00',
            'clock_out' => '13:00:00',
        ]);
    }
}
