<?php

declare(strict_types=1);

namespace Database\Factories\Platform;

use App\Domain\Platform\Enums\ModuleStatus;
use App\Domain\Platform\Models\TenantModule;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TenantModule>
 */
class TenantModuleFactory extends Factory
{
    protected $model = TenantModule::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'module_key' => 'hrm',
            'status' => ModuleStatus::Active,
            'enabled_at' => now(),
            'enabled_by_user_id' => null,
        ];
    }

    public function disabled(): static
    {
        return $this->state(fn (): array => [
            'status' => ModuleStatus::Disabled,
        ]);
    }

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(fn (): array => [
            'tenant_id' => $tenant->id,
        ]);
    }
}
