<?php

declare(strict_types=1);

namespace App\Domain\Platform\Actions;

use App\Domain\Platform\Enums\ModuleStatus;
use App\Domain\Platform\Models\TenantModule;
use App\Models\Company;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Company\Enums\CompanyStatus;
use App\Support\Identity\Enums\UserType;
use App\Support\Tenancy\Enums\TenantStatus;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Atomically creates:
 *   1. The Tenant row (status = Active)
 *   2. A default Company within that tenant (the tenant's "first company")
 *   3. The initial tenant_admin User (tenant_user; tenant_id / default+
 *      current company_id set)
 *   4. The tenant_admin role assignment scoped to this tenant
 *   5. A bootstrap tenant_modules row entitling HRM (status = Active,
 *      enabled_by_user_id = the acting SA's id)
 *
 * Returns the new entities + the one-time plaintext password (the
 * caller — TenantController::store — surfaces it in the response
 * exactly once and never persists it elsewhere).
 *
 * Transactional shape (Session 3 plan tightening #1):
 *   - The DB::transaction guarantees all-or-nothing persistence.
 *   - Inside the transaction, Company::booted() fires CompanyCreated::
 *     dispatch on the Eloquent `created` event. BootstrapHrmSettingsListener
 *     handles that event and creates the hrm_settings row.
 *   - If the listener fails for any reason (deploy mid-rollout, exception
 *     in the listener body, queue failure), the tenant + company would
 *     exist but hrm_settings would be missing — the §10.12 trap shape.
 *     Recovery: re-fire CompanyCreated::dispatch(Company) for the
 *     affected company. The listener is idempotent (firstOrCreate-style),
 *     so re-firing recovers the missing row without duplicating state.
 *     Test surface: a dedicated edge-case test in
 *     CreateTenantWithInitialAdminActionTest exercises this path
 *     (kill the listener subscription, run the action, verify the
 *     gap, re-fire, verify recovery).
 *
 * One-time password handling (Session 3 plan tightening #2):
 *   - The plaintext password is generated via Str::password(16) inside
 *     this method, hashed via Hash::make for users.password, and returned
 *     in the result. The plaintext NEVER touches Log::, Telescope-readable
 *     output, audit_logs, or any other store. Auditable's
 *     filterAttributesForAudit drops User::$hidden keys ('password' +
 *     'remember_token') from every audit row, so the user-creation audit
 *     event captures the actor identity without leaking the secret.
 *
 * Forgot-password recovery (Session 3 plan tightening #3):
 *   - users.password is a real BCrypt hash; standard Laravel password
 *     reset flows (Password facade, Notifications) work normally if
 *     the SA loses the one-time display. The endpoint isn't wired
 *     in this slice (separate auth concern, future slice); the
 *     test surface verifies the data shape is correct
 *     (Hash::check works against the returned plaintext) so the
 *     recovery path is unblocked when forgot-password ships.
 */
final class CreateTenantWithInitialAdminAction
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * @param  array{slug: string, name: string, legal_name?: string|null, country_code: string, default_currency: string, functional_currency: string, timezone: string}  $tenantData
     * @param  array{slug: string, name: string, legal_name?: string|null}  $companyData
     * @param  array{name: string, email: string}  $adminData
     */
    public function execute(
        array $tenantData,
        array $companyData,
        array $adminData,
        User $actingSuperAdmin,
    ): TenantCreationResult {
        // Generate the plaintext password OUTSIDE the transaction so a
        // rollback doesn't leak a "we tried this password" state. 16
        // chars from Str::password — letters + digits + symbols.
        $plaintextPassword = Str::password(16);

        /** @var TenantCreationResult $result */
        $result = DB::transaction(function () use (
            $tenantData,
            $companyData,
            $adminData,
            $actingSuperAdmin,
            $plaintextPassword,
        ): TenantCreationResult {
            // 1. Tenant — status defaults to Active for newly-created
            //    tenants. SA-side suspension is a separate update action.
            $tenant = Tenant::create([
                'slug' => $tenantData['slug'],
                'name' => $tenantData['name'],
                'legal_name' => $tenantData['legal_name'] ?? null,
                'country_code' => $tenantData['country_code'],
                'default_currency' => $tenantData['default_currency'],
                'functional_currency' => $tenantData['functional_currency'],
                'timezone' => $tenantData['timezone'],
                'status' => TenantStatus::Active,
            ]);

            // 2. Default Company — inherits the tenant's currency +
            //    timezone defaults; SA-side per-company customisation
            //    is a future feature. The CompanyCreated event fires
            //    via Company::booted() → BootstrapHrmSettingsListener
            //    materialises the hrm_settings row. Inside the
            //    transaction so the rollback covers it too.
            //
            //    TenantContext::asSystem() so the BelongsToCompany trait's
            //    auto-fill (which reads CompanyContext) doesn't fight
            //    the explicit tenant_id we're providing.
            $company = $this->tenantContext->asSystem(
                fn (): Company => Company::create([
                    'tenant_id' => $tenant->id,
                    'slug' => $companyData['slug'],
                    'name' => $companyData['name'],
                    'legal_name' => $companyData['legal_name'] ?? null,
                    'country_code' => $tenantData['country_code'],
                    'default_currency' => $tenantData['default_currency'],
                    'functional_currency' => $tenantData['functional_currency'],
                    'timezone' => $tenantData['timezone'],
                    'status' => CompanyStatus::Active,
                ]),
            );

            // 3. Initial tenant_admin user.
            $admin = User::create([
                'name' => $adminData['name'],
                'email' => $adminData['email'],
                'password' => Hash::make($plaintextPassword),
                'email_verified_at' => now(),
                'type' => UserType::TenantUser,
                'tenant_id' => $tenant->id,
                'default_company_id' => $company->id,
                'current_company_id' => $company->id,
            ]);

            // 4. tenant_admin role scoped to this tenant (Spatie team_id =
            //    tenant_id). assignTenantRole is the project-wide helper
            //    that sets the team_id correctly.
            $admin->assignTenantRole($tenant, 'tenant_admin');

            // 5. Bootstrap entitlement — HRM Active, enabled_by_user_id
            //    = the SA. Differs from the migration backfill (which
            //    uses NULL because no SA exists at migration time); UI-
            //    driven creation has an actor and captures it. acrossTenants()
            //    bypass on the model query is unnecessary here because
            //    Eloquent INSERTs don't pass through the global scope —
            //    but the BelongsToTenant trait's auto-fill still runs.
            //    We provide tenant_id explicitly so the trait short-
            //    circuits (per its early-return on non-null tenant_id).
            TenantModule::create([
                'tenant_id' => $tenant->id,
                'module_key' => 'hrm',
                'status' => ModuleStatus::Active,
                'enabled_at' => now(),
                'enabled_by_user_id' => $actingSuperAdmin->id,
            ]);

            return new TenantCreationResult(
                tenant: $tenant,
                company: $company,
                admin: $admin,
                initialAdminPassword: $plaintextPassword,
            );
        });

        return $result;
    }
}
