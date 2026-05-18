<?php

declare(strict_types=1);

namespace Database\Factories\HRM;

use App\Domain\HRM\Enums\EmployeeStatus;
use App\Domain\HRM\Models\Employee;
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
            'job_title' => $this->faker->optional(0.9)->jobTitle(),
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

    public function onLeave(): static
    {
        return $this->state(fn (): array => ['status' => EmployeeStatus::OnLeave]);
    }

    public function terminated(): static
    {
        return $this->state(fn (): array => ['status' => EmployeeStatus::Terminated]);
    }
}
