<?php

declare(strict_types=1);

namespace Database\Factories\HRM;

use App\Domain\HRM\Enums\DepartmentStatus;
use App\Domain\HRM\Models\Department;
use App\Models\Company;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Department>
 */
class DepartmentFactory extends Factory
{
    protected $model = Department::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            // Auto-builds a tenant + company if not pinned via forCompany().
            'tenant_id' => Tenant::factory(),
            'company_id' => Company::factory(),
            'code' => 'D-'.$this->faker->unique()->bothify('???###'),
            'name' => $this->faker->unique()->jobTitle().' Team',
            'description' => $this->faker->optional(0.7)->sentence(8),
            'status' => DepartmentStatus::Active,
        ];
    }

    /**
     * Anchor this department to an existing company (and its tenant).
     * Required when seeding multiple departments in a single company.
     */
    public function forCompany(Company $company): static
    {
        return $this->state(fn (): array => [
            'tenant_id' => $company->tenant_id,
            'company_id' => $company->id,
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (): array => ['status' => DepartmentStatus::Archived]);
    }
}
