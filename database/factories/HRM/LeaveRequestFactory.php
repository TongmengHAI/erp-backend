<?php

declare(strict_types=1);

namespace Database\Factories\HRM;

use App\Domain\HRM\Enums\LeaveRequestStatus;
use App\Domain\HRM\Enums\LeaveType;
use App\Domain\HRM\Models\Employee;
use App\Domain\HRM\Models\LeaveRequest;
use App\Models\Company;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

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
}
