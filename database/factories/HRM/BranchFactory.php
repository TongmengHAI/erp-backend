<?php

declare(strict_types=1);

namespace Database\Factories\HRM;

use App\Domain\HRM\Enums\BranchStatus;
use App\Domain\HRM\Models\Branch;
use App\Models\Company;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Branch>
 */
class BranchFactory extends Factory
{
    protected $model = Branch::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'company_id' => Company::factory(),
            'code' => 'B-'.$this->faker->unique()->bothify('???###'),
            'name' => $this->faker->company().' Branch',
            'description' => $this->faker->optional(0.5)->sentence(8),
            // Optional address fields — faker generates plausible
            // values but most rows in tests will exercise the null path.
            'address' => $this->faker->optional(0.7)->streetAddress(),
            'city' => $this->faker->optional(0.7)->city(),
            // Uppercase ISO 3166-1 alpha-2 — matches the FormRequest's
            // ^[A-Z]{2}$ regex. faker->countryCode() returns 2-char
            // codes already; toUpper() guards against any source-data drift.
            'country_code' => $this->faker->optional(0.7)
                ? strtoupper($this->faker->countryCode())
                : null,
            'phone' => $this->faker->optional(0.5)->phoneNumber(),
            'status' => BranchStatus::Active,
        ];
    }

    /**
     * Anchor this branch to an existing company (and its tenant).
     * Required when seeding multiple branches in a single company.
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
        return $this->state(fn (): array => ['status' => BranchStatus::Archived]);
    }
}
