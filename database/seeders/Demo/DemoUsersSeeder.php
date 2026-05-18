<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\Company;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Company\Actions\BackfillUsersToCompanyAction;
use App\Support\Company\Enums\CompanyStatus;
use App\Support\Tenancy\Enums\TenantStatus;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Demo users seeder — local dev only.
 *
 * Creates a deterministic minimal set of tenants, companies, and users so
 * the F3 integration smoke (and any later auth/permission debugging) has
 * data to exercise:
 *
 *   Tenant: Acme Trading Co. (active)
 *     └── Company: Acme Trading Co. (active)
 *           └── admin@acme.test / password — tenant_admin role
 *               (tenant.settings.manage, accounting.journal_entry.view,
 *                accounting.journal_entry.create)
 *
 *   Tenant: Suspended Co. (status=suspended)
 *     └── Company: Suspended Co. (active — the suspension is the tenant's)
 *           └── suspended@acme.test / password — no role
 *               (tenant_inactive path is exercised at /auth/me)
 *
 * NOT registered in DatabaseSeeder::run() — run explicitly with:
 *     php artisan db:seed --class="Database\Seeders\Demo\DemoUsersSeeder"
 *
 * Idempotent: re-running creates no duplicates. Tenants by slug, companies
 * by (tenant_id, slug), users by email. BackfillUsersToCompanyAction is
 * itself idempotent — it only fills null defaults.
 *
 * Depends on Framework\DefaultPermissionsSeeder + DefaultRolesSeeder having
 * been run first (they create the `tenant_admin` role this seeder uses).
 *
 * --- Company binding pattern ---
 *
 * Users are created FIRST with default_company_id=null and current_company_id=
 * null, then their Company is firstOrCreated, then BackfillUsersToCompanyAction
 * binds the user(s) to the company. This is the same code path a future
 * company-creation endpoint will use when an admin provisions a second
 * company for an existing tenant (CLAUDE.md §3 Approach A transition).
 * Running the action here in the seeder gives it a real caller and a real
 * test path beyond the focused unit tests.
 *
 * --- Other identity-source notes ---
 *
 * Future tenant-scoped writes (Employee, JournalEntry, etc.) MUST be wrapped
 * in TenantContext::asSystem() when run outside a request context. User,
 * Tenant, Company, audit_logs are identity-source models per CLAUDE.md §3 —
 * they don't need the wrapper.
 */
final class DemoUsersSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // Console context has no resolved tenant. Company is tenant-scoped
        // (BelongsToTenant), so any Company query — including firstOrCreate's
        // existence-check SELECT — triggers TenantScope and throws without a
        // wrapper. asSystem clears the scope for the duration. Tenant and
        // User are identity-source and don't need it; we wrap only the
        // run() body here for simplicity since Company queries are in scope.
        app(TenantContext::class)->asSystem(function (): void {
            $this->seedAll();
        });
    }

    private function seedAll(): void
    {
        // ─── Acme Trading Co. tenant + company + admin user ──────────────────
        $acmeTenant = Tenant::query()->firstOrCreate(
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

        $admin = User::query()->firstOrCreate(
            ['email' => 'admin@acme.test'],
            [
                'name' => 'Admin User',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'tenant_id' => $acmeTenant->id,
                'current_tenant_id' => $acmeTenant->id,
                // default/current company intentionally null — they get
                // backfilled by BackfillUsersToCompanyAction below, which
                // gives the action a real caller in this seed path.
            ],
        );

        $acmeCompany = Company::query()->firstOrCreate(
            ['tenant_id' => $acmeTenant->id, 'slug' => 'acme-trading'],
            [
                'name' => 'Acme Trading Co.',
                'legal_name' => 'Acme Trading Co., Ltd.',
                'country_code' => 'KH',
                'default_currency' => 'USD',
                'functional_currency' => 'USD',
                'timezone' => 'Asia/Phnom_Penh',
                'status' => CompanyStatus::Active,
            ],
        );

        // Route through the action rather than setting default_company_id
        // inline. Idempotent — on re-run, the user already has the binding
        // and the action skips them.
        app(BackfillUsersToCompanyAction::class)->execute($acmeCompany);

        // Idempotent role assignment scoped to the Acme tenant. HasTenantRoles
        // sets Spatie's team_id for the call and restores it on exit.
        $admin->assignTenantRole($acmeTenant, 'tenant_admin');

        // ─── Suspended Co. tenant + company + suspended user ─────────────────
        $suspendedTenant = Tenant::query()->firstOrCreate(
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
        if ($suspendedTenant->status !== TenantStatus::Suspended) {
            $suspendedTenant->forceFill(['status' => TenantStatus::Suspended])->save();
        }

        $suspendedUser = User::query()->firstOrCreate(
            ['email' => 'suspended@acme.test'],
            [
                'name' => 'Suspended User',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'tenant_id' => $suspendedTenant->id,
                'current_tenant_id' => $suspendedTenant->id,
            ],
        );

        $suspendedCompany = Company::query()->firstOrCreate(
            ['tenant_id' => $suspendedTenant->id, 'slug' => 'suspended-co-main'],
            [
                'name' => 'Suspended Co.',
                'legal_name' => 'Suspended Co., Ltd.',
                'country_code' => 'KH',
                'default_currency' => 'USD',
                'functional_currency' => 'USD',
                'timezone' => 'Asia/Phnom_Penh',
                'status' => CompanyStatus::Active,
            ],
        );

        // Bind the suspended user to the company even though the tenant is
        // suspended — the binding is structural; the suspension is
        // enforced at the tenant layer before company context matters.
        // ResolveTenant throws tenant_inactive before ResolveCompany ever
        // sees this user, so the user.default_company_id is dormant.
        app(BackfillUsersToCompanyAction::class)->execute($suspendedCompany);

        // No role assignment for the suspended user — the suspension path
        // is intercepted at /auth/me before permission resolution happens.
        unset($suspendedUser);

        $this->command->info(
            'DemoUsersSeeder: seeded admin@acme.test (Acme Trading Co., active, bound to Acme Trading Co. company) + suspended@acme.test (Suspended Co., suspended).'
        );
    }
}
