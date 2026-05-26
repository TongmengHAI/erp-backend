<?php

declare(strict_types=1);

namespace Database\Factories\HRM;

use App\Domain\HRM\Enums\PositionStatus;
use App\Domain\HRM\Models\Position;
use App\Models\Company;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Position>
 */
class PositionFactory extends Factory
{
    protected $model = Position::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            // Auto-builds a tenant + company if not pinned via forCompany().
            'tenant_id' => Tenant::factory(),
            'company_id' => Company::factory(),
            'code' => 'P-'.$this->faker->unique()->bothify('???###'),
            'title' => $this->faker->unique()->jobTitle(),
            'description' => $this->faker->optional(0.7)->sentence(8),
            'status' => PositionStatus::Active,
        ];
    }

    /**
     * Anchor this position to an existing company (and its tenant).
     * Required when seeding multiple positions in a single company.
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
        return $this->state(fn (): array => ['status' => PositionStatus::Archived]);
    }
}
