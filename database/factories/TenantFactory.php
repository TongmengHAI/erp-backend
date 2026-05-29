<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use App\Support\Tenancy\Enums\TenantStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

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

    /**
     * Mirror production semantics in tests: every new tenant gets an
     * Active HRM entitlement row, matching what the
     * 2026_06_05 tenant_modules migration backfill does for tenants
     * existing at deploy time. Without this hook, every test that hits
     * a /api/v1/hrm/* or /api/v1/admin/hrm/* route would 403 at the
     * module:hrm gate (factory-created tenants exist post-migration,
     * so the backfill doesn't cover them).
     *
     * Tests that need a tenant WITHOUT entitlement (e.g. the
     * EnforceModuleEntitlement "tenant with NO HRM row" test) opt out
     * by calling `->withoutEntitlement()` below.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Tenant $tenant): void {
            // Raw DB::table() bypasses TenantScope. We're inside a
            // factory hook with no TenantContext set; routing through
            // the Eloquent query builder would throw
            // TenantContextMissingException despite us explicitly
            // setting tenant_id. The migration backfill uses the same
            // raw-write shape for the same reason.
            DB::table('tenant_modules')->insert([
                'tenant_id' => $tenant->id,
                'module_key' => 'hrm',
                'status' => 'active',
                'enabled_at' => now(),
                'enabled_by_user_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    /**
     * Opt out of the default HRM entitlement. Used by tests that need
     * to exercise the "no entitlement row" or "disabled entitlement"
     * paths through EnforceModuleEntitlement.
     */
    public function withoutEntitlement(): static
    {
        return $this->afterCreating(function (Tenant $tenant): void {
            // Raw DB::table() — same reasoning as configure() above.
            // Hard delete the bootstrap row outright (no soft-delete);
            // tests that need the row gone want it gone.
            DB::table('tenant_modules')
                ->where('tenant_id', $tenant->id)
                ->delete();
        });
    }
}
