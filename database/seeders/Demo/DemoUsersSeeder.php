<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\Enums\TenantStatus;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Demo users seeder — local dev only.
 *
 * Creates a deterministic minimal set of tenants + users so the F3
 * integration smoke (and any later auth/permission debugging) has data
 * to exercise:
 *
 *   - Acme Trading Co. (active)
 *       └── admin@acme.test / password — tenant_admin role
 *                                        (tenant.settings.manage,
 *                                         accounting.journal_entry.view,
 *                                         accounting.journal_entry.create)
 *
 *   - Suspended Co. (status=suspended)
 *       └── suspended@acme.test / password — no role assigned
 *           (the tenant_inactive path is exercised at /auth/me, not via roles)
 *
 * NOT registered in DatabaseSeeder::run() — run explicitly with:
 *     php artisan db:seed --class="Database\Seeders\Demo\DemoUsersSeeder"
 *
 * Idempotent: re-running creates no duplicates. Tenants are looked up by slug,
 * users by email. Role assignment is per-tenant-scoped via assignTenantRole
 * which Spatie internally treats as a no-op if already assigned.
 *
 * Depends on Framework\DefaultPermissionsSeeder + DefaultRolesSeeder having
 * been run first (they create the `tenant_admin` role this seeder uses).
 *
 * Note: future tenant-scoped writes (Employee, JournalEntry, etc.) MUST be
 * wrapped in TenantContext::asSystem() when run outside a request context.
 * User and Tenant are identity-source models, not tenant-scoped — they
 * don't need the wrapper. See CLAUDE.md §3.
 */
final class DemoUsersSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $acme = Tenant::query()->firstOrCreate(
            ['slug' => 'acme'],
            [
                'name' => 'Acme Trading Co.',
                'legal_name' => 'Acme Trading Co., Ltd.',
                'country_code' => 'KH',
                'default_currency' => 'USD',
                'functional_currency' => 'USD',
                'timezone' => 'Asia/Phnom_Penh',
                'status' => TenantStatus::Active,
            ],
        );

        $suspended = Tenant::query()->firstOrCreate(
            ['slug' => 'suspended-co'],
            [
                'name' => 'Suspended Co.',
                'legal_name' => 'Suspended Co., Ltd.',
                'country_code' => 'KH',
                'default_currency' => 'USD',
                'functional_currency' => 'USD',
                'timezone' => 'Asia/Phnom_Penh',
                'status' => TenantStatus::Suspended,
            ],
        );

        // Re-assert status on subsequent runs in case the row was tweaked
        // mid-debugging — keeps the seeder's promise truthful.
        if ($suspended->status !== TenantStatus::Suspended) {
            $suspended->forceFill(['status' => TenantStatus::Suspended])->save();
        }

        $admin = User::query()->firstOrCreate(
            ['email' => 'admin@acme.test'],
            [
                'name' => 'Admin User',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'tenant_id' => $acme->id,
                'current_tenant_id' => $acme->id,
            ],
        );

        // Idempotent role assignment scoped to the Acme tenant. The
        // HasTenantRoles trait sets Spatie's team_id for the duration of
        // the call and restores it on exit.
        $admin->assignTenantRole($acme, 'tenant_admin');

        User::query()->firstOrCreate(
            ['email' => 'suspended@acme.test'],
            [
                'name' => 'Suspended User',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'tenant_id' => $suspended->id,
                'current_tenant_id' => $suspended->id,
            ],
        );

        // No role assignment for the suspended user — the suspension path
        // is intercepted at /auth/me before permission resolution happens.

        $this->command->info('DemoUsersSeeder: seeded admin@acme.test (Acme, active) + suspended@acme.test (Suspended Co., suspended).');
    }
}
