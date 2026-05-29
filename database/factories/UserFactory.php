<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Identity\Enums\UserType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'tenant_id' => Tenant::factory(),
            'current_tenant_id' => null,
            'default_company_id' => null,
            'current_company_id' => null,
            // Explicit default. The migration sets a DB-level default
            // ('tenant_user') so existing rows backfill, but the factory
            // model doesn't refresh from the DB after insert — so
            // omitting it here would leave the in-memory $user->type
            // null until a fresh fetch. UserResource accesses ->value on
            // the cast enum; null would throw. Setting it explicitly
            // keeps factory-created models internally consistent.
            'type' => UserType::TenantUser,
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes): array => [
            'email_verified_at' => null,
        ]);
    }

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(fn (array $attributes): array => [
            'tenant_id' => $tenant->id,
        ]);
    }

    /**
     * Super Admin user state. All four tenant/company FK columns set to
     * NULL — the composite DB CHECK 'users_super_admin_no_tenant_or_company_check'
     * would reject any other shape.
     */
    public function superAdmin(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => UserType::SuperAdmin,
            'tenant_id' => null,
            'current_tenant_id' => null,
            'default_company_id' => null,
            'current_company_id' => null,
        ]);
    }
}
