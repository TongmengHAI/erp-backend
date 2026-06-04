<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────────
// CreateTenantWithInitialAdminActionTest — the three deliberate tests the
// Session 3 plan called out:
//
//   1. §10.12 edge case (LOAD-BEARING). Listener-failure-mid-create is the
//      "visible feature works in dev where seeders run together, breaks at
//      runtime when a fresh tenant hits the missing row" trap shape.
//      Test: temporarily unsubscribe BootstrapHrmSettingsListener; run the
//      action; verify the tenant + company + admin exist but hrm_settings
//      is missing; re-fire CompanyCreated::dispatch; verify hrm_settings
//      now exists. Proves the system is RECOVERABLE if the listener
//      fails — re-firing the event closes the gap.
//
//   2. Audit log does NOT contain plaintext password (LOAD-BEARING). The
//      User-creation audit row's `after` JSON must NOT have a `password`
//      key (or its BCrypt hash). Auditable's filterAttributesForAudit
//      drops User::$hidden keys, so the discipline is already in place
//      — this test pins it so a future change to the Auditable trait or
//      User model can't silently leak the secret.
//
//   3. Forgot-password recovery data shape (LOAD-BEARING). The user
//      receives a real BCrypt hash that Hash::check verifies against
//      the returned plaintext, and rejects against a different plaintext.
//      Standard Laravel password-reset flows (Password broker,
//      Notifications) work normally if the SA loses the one-time
//      display. The actual forgot-password endpoint is a future-slice
//      concern (out of scope per §7.B); this test pins the DATA SHAPE
//      so the recovery path is unblocked when the endpoint ships.
// ─────────────────────────────────────────────────────────────────────────────

use App\Domain\HRM\Listeners\BootstrapHrmSettingsListener;
use App\Domain\HRM\Models\HrmSettings;
use App\Domain\Platform\Actions\CreateTenantWithInitialAdminAction;
use App\Domain\Platform\Models\TenantModule;
use App\Models\Company;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Company\Events\CompanyCreated;
use App\Support\Identity\Enums\UserType;
use Database\Seeders\Framework\DefaultPermissionsSeeder;
use Database\Seeders\Framework\DefaultRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed([DefaultPermissionsSeeder::class, DefaultRolesSeeder::class]);
    $this->sa = User::factory()->superAdmin()->create();
});

function validActionPayload(): array
{
    return [
        'tenantData' => [
            'slug' => 'acme-trading',
            'name' => 'Acme Trading Co.',
            'legal_name' => 'Acme Trading Co., Ltd.',
            'country_code' => 'KH',
            'default_currency' => 'USD',
            'functional_currency' => 'USD',
            'timezone' => 'Asia/Phnom_Penh',
        ],
        'companyData' => [
            'slug' => 'acme-trading-main',
            'name' => 'Acme Trading Main',
            'legal_name' => 'Acme Trading Co., Ltd.',
        ],
        'adminData' => [
            'name' => 'Sokha Chan',
            'email' => 'sokha@acme.kh',
        ],
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// 1. §10.12 edge case
// ─────────────────────────────────────────────────────────────────────────────

it('LOAD-BEARING §10.12: re-firing CompanyCreated recovers hrm_settings when listener failed mid-create', function (): void {
    // Unsubscribe the listener for the duration of this test. Models the
    // §10.12 trap shape: the action runs, persists the tenant + company,
    // BUT the listener doesn't fire (deploy mid-rollout, listener
    // exception, queue failure — any of these surface the same gap).
    Event::forget(CompanyCreated::class);

    $payload = validActionPayload();
    $result = app(CreateTenantWithInitialAdminAction::class)->execute(
        tenantData: $payload['tenantData'],
        companyData: $payload['companyData'],
        adminData: $payload['adminData'],
        actingSuperAdmin: $this->sa,
    );

    // Tenant + Company + Admin all exist (the transaction itself
    // committed; only the post-commit listener was the missing link).
    expect($result->tenant->exists)->toBeTrue();
    expect($result->company->exists)->toBeTrue();
    expect($result->admin->exists)->toBeTrue();

    // hrm_settings row is MISSING — the §10.12 trap exposed. In
    // production this is the "tenant_admin opens the Settings page and
    // gets a 500" failure mode.
    $settingsBefore = HrmSettings::query()
        ->withoutGlobalScopes()
        ->where('company_id', $result->company->id)
        ->count();
    expect($settingsBefore)->toBe(0);

    // RECOVERY: re-subscribe the listener + re-fire the event.
    // BootstrapHrmSettingsListener is idempotent (firstOrCreate-style),
    // so re-firing closes the gap without duplicating state.
    Event::listen(CompanyCreated::class, [BootstrapHrmSettingsListener::class, 'handle']);
    CompanyCreated::dispatch($result->company);

    $settingsAfter = HrmSettings::query()
        ->withoutGlobalScopes()
        ->where('company_id', $result->company->id)
        ->count();
    expect($settingsAfter)->toBe(1);
});

it('happy path: hrm_settings is materialized when the listener IS registered (verification of the un-killed path)', function (): void {
    // Inverse case: with the listener registered, the action runs and
    // hrm_settings appears post-commit. Verifies the §10.12 test above
    // is exercising a REAL gap (not a tautology where settings never
    // materialize regardless).
    $payload = validActionPayload();
    $result = app(CreateTenantWithInitialAdminAction::class)->execute(
        tenantData: $payload['tenantData'],
        companyData: $payload['companyData'],
        adminData: $payload['adminData'],
        actingSuperAdmin: $this->sa,
    );

    $settings = HrmSettings::query()
        ->withoutGlobalScopes()
        ->where('company_id', $result->company->id)
        ->count();
    expect($settings)->toBe(1);
});

// ─────────────────────────────────────────────────────────────────────────────
// 2. Audit log does NOT contain plaintext password
// ─────────────────────────────────────────────────────────────────────────────

it('LOAD-BEARING: audit_logs row for User creation does NOT contain plaintext OR hashed password', function (): void {
    $payload = validActionPayload();
    $result = app(CreateTenantWithInitialAdminAction::class)->execute(
        tenantData: $payload['tenantData'],
        companyData: $payload['companyData'],
        adminData: $payload['adminData'],
        actingSuperAdmin: $this->sa,
    );

    /** @var object{after: string} $audit */
    $audit = DB::table('audit_logs')
        ->where('auditable_type', User::class)
        ->where('auditable_id', $result->admin->id)
        ->where('action', 'created')
        ->first();

    expect($audit)->not->toBeNull();

    // The `after` column is a JSON string (jsonb column). Decode + assert
    // the password key is absent. Auditable's filterAttributesForAudit
    // drops User::$hidden keys ('password' + 'remember_token').
    /** @var array<string, mixed> $afterJson */
    $afterJson = json_decode($audit->after, associative: true);
    expect($afterJson)->not->toHaveKey('password');
    expect($afterJson)->not->toHaveKey('remember_token');

    // Defense-in-depth: also assert the plaintext password string itself
    // does NOT appear anywhere in the serialised audit row (covers a
    // hypothetical future Auditable change that might leak via metadata).
    expect($audit->after)->not->toContain($result->initialAdminPassword);
});

// ─────────────────────────────────────────────────────────────────────────────
// 3. Forgot-password recovery data shape
// ─────────────────────────────────────────────────────────────────────────────

it('LOAD-BEARING: created admin user has a BCrypt hash; Hash::check verifies the returned plaintext', function (): void {
    $payload = validActionPayload();
    $result = app(CreateTenantWithInitialAdminAction::class)->execute(
        tenantData: $payload['tenantData'],
        companyData: $payload['companyData'],
        adminData: $payload['adminData'],
        actingSuperAdmin: $this->sa,
    );

    // The returned plaintext is what Hash::check verifies against the
    // stored hash. A standard login attempt with this plaintext works,
    // and (when the forgot-password endpoint ships) Password broker
    // can issue a reset token against this user.
    expect(Hash::check($result->initialAdminPassword, $result->admin->password))->toBeTrue();

    // Reverse test: a different plaintext is rejected.
    expect(Hash::check('not the password', $result->admin->password))->toBeFalse();

    // Password broker preconditions: the user record itself is
    // resolvable via email — this is what Password::sendResetLink would
    // do. Confirming the data shape is forgot-password-ready without
    // requiring the endpoint to be wired.
    expect(Password::getUser(['email' => $result->admin->email]))->not->toBeNull();
});

// ─────────────────────────────────────────────────────────────────────────────
// Action invariants — proves the OTHER load-bearing pieces of the action:
//   - entitlement enabled_by_user_id is the SA's id (not NULL)
//   - tenant_admin role assignment scoped correctly
//   - type=tenant_user on the created admin
// ─────────────────────────────────────────────────────────────────────────────

it('LOAD-BEARING: entitlement row is created with enabled_by_user_id = the acting SA (not NULL)', function (): void {
    $payload = validActionPayload();
    $result = app(CreateTenantWithInitialAdminAction::class)->execute(
        tenantData: $payload['tenantData'],
        companyData: $payload['companyData'],
        adminData: $payload['adminData'],
        actingSuperAdmin: $this->sa,
    );

    /** @var TenantModule $entitlement */
    $entitlement = TenantModule::query()
        ->acrossTenants()
        ->where('tenant_id', $result->tenant->id)
        ->where('module_key', 'hrm')
        ->first();

    expect($entitlement)->not->toBeNull();
    expect($entitlement->enabled_by_user_id)->toBe($this->sa->id);
    expect($entitlement->status->value)->toBe('active');
});

it('admin user has type=tenant_user and is assigned tenant_admin role scoped to the new tenant', function (): void {
    $payload = validActionPayload();
    $result = app(CreateTenantWithInitialAdminAction::class)->execute(
        tenantData: $payload['tenantData'],
        companyData: $payload['companyData'],
        adminData: $payload['adminData'],
        actingSuperAdmin: $this->sa,
    );

    expect($result->admin->type)->toBe(UserType::TenantUser);
    expect($result->admin->tenant_id)->toBe($result->tenant->id);
    expect($result->admin->default_company_id)->toBe($result->company->id);

    // Scope role-check to the tenant via Spatie's team setup.
    app(PermissionRegistrar::class)->setPermissionsTeamId($result->tenant->id);
    expect($result->admin->hasRole('tenant_admin'))->toBeTrue();
});

it('rolls back atomically if any inner step fails — partial state is never persisted', function (): void {
    // Simulate the User creation failing mid-transaction by reusing
    // an existing email (DB unique on users.email). The whole
    // transaction must roll back — tenant + company that were already
    // inserted must NOT remain.
    User::factory()->forTenant(Tenant::factory()->create())->create([
        'email' => 'sokha@acme.kh',
    ]);

    $payload = validActionPayload();

    $thrown = false;
    try {
        app(CreateTenantWithInitialAdminAction::class)->execute(
            tenantData: $payload['tenantData'],
            companyData: $payload['companyData'],
            adminData: $payload['adminData'],
            actingSuperAdmin: $this->sa,
        );
    } catch (Throwable) {
        $thrown = true;
    }

    expect($thrown)->toBeTrue('Expected the action to throw on email collision.');

    // Critically: tenants.slug='acme-trading' must NOT exist — the
    // outer DB::transaction rolled back the tenant insert when the
    // user insert threw. Without the rollback discipline, a future SA
    // retry would 422 on slug collision against a phantom tenant.
    // Tenant has no tenant_id (it IS the tenant boundary); Company has
    // BelongsToTenant — bypass the scope since no TenantContext is set
    // in this test path.
    expect(Tenant::query()->where('slug', 'acme-trading')->exists())->toBeFalse();
    expect(Company::query()->withoutGlobalScopes()->where('slug', 'acme-trading-main')->exists())->toBeFalse();
});
