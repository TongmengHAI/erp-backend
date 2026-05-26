<?php

declare(strict_types=1);

namespace Database\Factories\HRM;

use App\Domain\HRM\Enums\LeaveType;
use App\Domain\HRM\Models\Employee;
use App\Domain\HRM\Models\LeaveBalance;
use App\Models\Company;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeaveBalance>
 */
class LeaveBalanceFactory extends Factory
{
    protected $model = LeaveBalance::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'company_id' => Company::factory(),
            'employee_id' => Employee::factory(),
            // Annual is the more common allocated type; sick() helper below
            // pins it explicitly when callers need the other variant.
            'leave_type' => LeaveType::Annual,
            'period_year' => 2026,
            'allocated_days' => 14.0,
            'notes' => null,
        ];
    }

    /**
     * Anchor this balance to an existing employee (and their tenant +
     * company). Standard mirror of LeaveRequestFactory::forEmployee.
     */
    public function forEmployee(Employee $employee): static
    {
        return $this->state(fn (): array => [
            'tenant_id' => $employee->tenant_id,
            'company_id' => $employee->company_id,
            'employee_id' => $employee->id,
        ]);
    }

    public function sick(): static
    {
        return $this->state(fn (): array => [
            'leave_type' => LeaveType::Sick,
            'allocated_days' => 7.0,
        ]);
    }

    public function annual(): static
    {
        return $this->state(fn (): array => [
            'leave_type' => LeaveType::Annual,
            'allocated_days' => 14.0,
        ]);
    }
}
