<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Identity\Models\Invitation;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Spatie\Permission\Models\Role;

/**
 * @extends Factory<Invitation>
 */
final class InvitationFactory extends Factory
{
    protected $model = Invitation::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $rawToken = Invitation::generateRawToken();

        return [
            'tenant_id' => Tenant::factory(),
            'email' => fake()->unique()->safeEmail(),
            'name' => fake()->name(),
            'role_id' => Role::query()->where('name', 'tenant_admin')->where('guard_name', 'web')->value('id')
                ?? Role::query()->value('id')
                ?? 0,
            'token_hash' => Invitation::hashToken($rawToken),
            'invited_by_user_id' => User::factory(),
            'expires_at' => now()->addDays(7),
            'accepted_at' => null,
            'accepted_user_id' => null,
            'cancelled_at' => null,
            'cancelled_by_user_id' => null,
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes): array => [
            'expires_at' => now()->subDay(),
        ]);
    }

    public function cancelled(User $by): static
    {
        return $this->state(fn (array $attributes): array => [
            'cancelled_at' => now(),
            'cancelled_by_user_id' => $by->id,
        ]);
    }

    public function accepted(User $user): static
    {
        return $this->state(fn (array $attributes): array => [
            'accepted_at' => now(),
            'accepted_user_id' => $user->id,
        ]);
    }
}
