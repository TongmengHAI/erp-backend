<?php

declare(strict_types=1);

namespace Database\Factories\HRM;

use App\Domain\HRM\Enums\EmployeeStatus;
use App\Domain\HRM\Models\Employee;
use App\Domain\HRM\Models\Position;
use App\Models\Company;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    protected $model = Employee::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            // Auto-builds a tenant + company if not pinned via forCompany().
            'tenant_id' => Tenant::factory(),
            'company_id' => Company::factory(),
            'employee_code' => 'E-'.$this->faker->unique()->numberBetween(1000, 99999),
            'full_name' => $this->faker->name(),
            'email' => $this->faker->optional(0.8)->safeEmail(),
            // position_id defaults to null. Callers wanting a position
            // chain ->forPosition(Position) (mirror of forDepartment).
            'hire_date' => $this->faker->dateTimeBetween('-5 years', '-1 day')->format('Y-m-d'),
            'status' => EmployeeStatus::Active,
        ];
    }

    /**
     * Anchor this employee to an existing company (and its tenant). Required
     * when seeding multiple employees in a single company.
     */
    public function forCompany(Company $company): static
    {
        return $this->state(fn (): array => [
            'tenant_id' => $company->tenant_id,
            'company_id' => $company->id,
        ]);
    }

    /**
     * Anchor this employee to an existing Position. Same-(tenant, company)
     * consistency is the caller's responsibility — the FormRequest's
     * scoped-exists rule enforces it at the HTTP boundary; this factory
     * helper is for test fixtures that already know they're aligned.
     */
    public function forPosition(Position $position): static
    {
        return $this->state(fn (): array => [
            'position_id' => $position->id,
        ]);
    }

    public function onLeave(): static
    {
        return $this->state(fn (): array => ['status' => EmployeeStatus::OnLeave]);
    }

    public function terminated(): static
    {
        return $this->state(fn (): array => ['status' => EmployeeStatus::Terminated]);
    }
}
