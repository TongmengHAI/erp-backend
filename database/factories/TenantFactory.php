<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use App\Support\Tenancy\Enums\TenantStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = $this->faker->company();

        return [
            'slug' => $this->faker->unique()->slug(2),
            'name' => $name,
            'legal_name' => $name.' Co., Ltd.',
            'country_code' => 'KH',
            'default_currency' => 'USD',
            'functional_currency' => 'USD',
            'timezone' => 'Asia/Phnom_Penh',
            'status' => TenantStatus::Active,
            'settings' => null,
        ];
    }

    public function suspended(): static
    {
        return $this->state(fn (): array => ['status' => TenantStatus::Suspended]);
    }

    public function archived(): static
    {
        return $this->state(fn (): array => ['status' => TenantStatus::Archived]);
    }

    public function khrFunctional(): static
    {
        return $this->state(fn (): array => [
            'default_currency' => 'KHR',
            'functional_currency' => 'KHR',
        ]);
    }
}
