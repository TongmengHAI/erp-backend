<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Company;
use App\Models\Tenant;
use App\Support\Company\Enums\CompanyStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = $this->faker->company();

        return [
            'tenant_id' => Tenant::factory(),
            'slug' => $this->faker->unique()->slug(2),
            'name' => $name,
            'legal_name' => $name.' Co., Ltd.',
            'country_code' => 'KH',
            'default_currency' => 'USD',
            'functional_currency' => 'USD',
            'timezone' => 'Asia/Phnom_Penh',
            'status' => CompanyStatus::Active,
            'settings' => null,
        ];
    }

    public function archived(): static
    {
        return $this->state(fn (): array => ['status' => CompanyStatus::Archived]);
    }

    public function khrFunctional(): static
    {
        return $this->state(fn (): array => [
            'default_currency' => 'KHR',
            'functional_currency' => 'KHR',
        ]);
    }

    /**
     * Anchor this company to an existing tenant rather than letting the
     * factory create a fresh one. Required when seeding multiple companies
     * under the same tenant (Cambodian holding-group shape).
     */
    public function forTenant(Tenant $tenant): static
    {
        return $this->state(fn (): array => ['tenant_id' => $tenant->id]);
    }
}
