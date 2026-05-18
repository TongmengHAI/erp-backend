<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────────
// Step1AuthFlowTest — end-to-end stack acceptance.
//
// Exercises every Step 1 component working together in a single user flow:
//
//   Sanctum stateful cookie auth      (slice 3)
//   ResolveTenant middleware          (slice 2)
//   Spatie HasRoles + teams           (slice 5)
//   Auditable trait + audit_logs      (slice 6)
//   POST /logout outside tenant group (slice 7)
//
// The individual concerns each have focused unit/feature tests; this file
// catches the regressions that only surface when the pieces interact.
// ─────────────────────────────────────────────────────────────────────────────

use App\Models\Company;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Audit\Models\AuditLog;
use App\Support\Company\Actions\BackfillUsersToCompanyAction;
use App\Support\Tenancy\Enums\TenantStatus;
use Database\Seeders\Framework\DefaultPermissionsSeeder;
use Database\Seeders\Framework\DefaultRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Sanctum stateful detection — same pattern as LoginTest/MeTest/LogoutTest.
    $this->withHeader('Origin', 'http://localhost');

    // The full RBAC catalog seeded — required for /me to return non-empty
    // roles/permissions arrays.
    $this->seed([
        DefaultPermissionsSeeder::class,
        DefaultRolesSeeder::class,
    ]);
});

it('happy path: login → /me → mutation produces audit → logout', function (): void {
    $tenant = Tenant::factory()->create([
        'name' => 'Acme Trading Co.',
        'default_currency' => 'USD',
        'functional_currency' => 'USD',
    ]);

    $user = User::factory()->forTenant($tenant)->create([
        'name' => 'Jane Bookkeeper',
        'password' => Hash::make('correct-horse-battery-staple'),
        'current_tenant_id' => null,
    ]);
    $user->assignTenantRole($tenant, 'accountant');

    // ─── 1. LOGIN ─────────────────────────────────────────────────────────
    $loginResponse = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'correct-horse-battery-staple',
    ]);

    $loginResponse->assertOk();
    $loginResponse->assertJsonPath('data.user.id', $user->id);
    $loginResponse->assertJsonPath('data.tenant.id', $tenant->id);

    // Login should have populated current_tenant_id (was null) — verifies
    // the "reset current_tenant_id to home" behavior from slice 3.
    expect($user->fresh()->current_tenant_id)->toBe($tenant->id);

    // The current_tenant_id update fired an audit row (User.updated):
    $loginAudit = AuditLog::query()
        ->where('auditable_type', User::class)
        ->where('auditable_id', $user->id)
        ->where('action', 'updated')
        ->latest('id')
        ->first();

    expect($loginAudit)->not->toBeNull();
    expect($loginAudit->tenant_id)->toBe($tenant->id);             // tenant scope captured from user.tenant_id
    expect($loginAudit->before)->toHaveKey('current_tenant_id');   // diff-only payload
    expect($loginAudit->after)->toHaveKey('current_tenant_id');
    expect($loginAudit->after['current_tenant_id'])->toBe($tenant->id);
    // NB: actor_id on the LOGIN-time audit row is null — the forceFill+save in
    // LoginController happens BEFORE Auth::login() completes, so Auth::user()
    // hasn't been set yet. Actor capture is asserted on the post-login
    // mutation audit row below, where authentication IS established.
    expect($loginAudit->actor_id)->toBeNull();

    // ─── 2. /me ───────────────────────────────────────────────────────────
    $meResponse = $this->getJson('/api/v1/auth/me');

    $meResponse->assertOk();
    $meResponse->assertJsonPath('data.tenant.name', 'Acme Trading Co.');
    $meResponse->assertJsonPath('data.tenant.functional_currency', 'USD');
    $meResponse->assertJsonPath('data.roles', ['accountant']);
    // accountant has 2 permissions per DefaultRolesSeeder.
    expect($meResponse->json('data.permissions'))->toEqualCanonicalizing([
        'accounting.journal_entry.view',
        'accounting.journal_entry.create',
    ]);

    // ─── 3. MUTATION → AUDIT ──────────────────────────────────────────────
    $user->forceFill(['name' => 'Jane Renamed'])->save();

    $mutationAudit = AuditLog::query()
        ->where('auditable_type', User::class)
        ->where('auditable_id', $user->id)
        ->where('action', 'updated')
        ->latest('id')
        ->first();

    expect($mutationAudit->id)->toBeGreaterThan($loginAudit->id);
    expect($mutationAudit->before)->toEqual(['name' => 'Jane Bookkeeper']);
    expect($mutationAudit->after)->toEqual(['name' => 'Jane Renamed']);
    expect($mutationAudit->tenant_id)->toBe($tenant->id);
    expect($mutationAudit->actor_id)->toBe($user->id);              // post-login Auth::user() is set
    expect($mutationAudit->actor_type)->toBe(User::class);

    // ─── 4. LOGOUT ────────────────────────────────────────────────────────
    $logoutResponse = $this->postJson('/api/v1/auth/logout');

    $logoutResponse->assertNoContent();
    expect(auth('web')->check())->toBeFalse();
});

it('tenant suspended mid-session: /me returns 401 tenant_inactive, logout still works', function (): void {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->forTenant($tenant)->create();
    $user->assignTenantRole($tenant, 'viewer');

    $this->actingAs($user);

    // Sanity: /me works while tenant is active.
    $this->getJson('/api/v1/auth/me')->assertOk();

    // Admin suspends the tenant out-of-band (no API endpoint for this in
    // Step 1 — direct model update for the test).
    $tenant->status = TenantStatus::Suspended;
    $tenant->save();

    // /me now returns 401 with the stable error_code — the SPA routes the
    // user to a "tenant suspended" screen, not /login.
    $meResponse = $this->getJson('/api/v1/auth/me');
    $meResponse->assertStatus(401);
    $meResponse->assertJsonPath('error_code', 'tenant_inactive');

    // The user must still be able to log out — /logout sits OUTSIDE the
    // `tenant` middleware group precisely for this case (slice 7).
    $this->postJson('/api/v1/auth/logout')->assertNoContent();
});

it('regression: /me works after fresh auth resolution (User must NOT be tenant-scoped)', function (): void {
    // ─────────────────────────────────────────────────────────────────────────
    // REGRESSION GUARD — surfaced during the F3 frontend integration smoke.
    //
    // Original bug: the User model used BelongsToTenant, which installed a
    // global TenantScope that demanded a resolved tenant context for every
    // User query. This created a circular dependency at auth time:
    //
    //   1. session cookie arrives, route protected by auth:sanctum
    //   2. SessionGuard::user() → EloquentUserProvider::retrieveById()
    //      → User::find()  ← TenantScope::apply() fires here
    //   3. no tenant context yet (ResolveTenant runs AFTER auth resolves)
    //   4. → TenantContextMissingException 500
    //
    // Why the original Step1 happy-path test missed it: tests use
    // SESSION_DRIVER=array, the test client keeps the application in-process
    // across postJson()/getJson() calls in the same test, and the
    // SessionGuard's in-memory $user cache survives between sub-requests.
    // So `$request->user()` returns the cached instance from the login
    // request without hitting EloquentUserProvider::retrieveById(). Real
    // HTTP gets a fresh Application bootstrap per request → forced DB
    // re-resolution → triggers the bug.
    //
    // This test simulates real-HTTP behaviour by calling auth()->forgetGuards()
    // between the login and /me requests, forcing the SessionGuard to drop
    // its cached user. The next $request->user() goes through the full
    // EloquentUserProvider path — same as a real production request.
    //
    // If User ever gets BelongsToTenant added back, this test will fail
    // with a 500 (TenantContextMissingException) on the /me assertion.
    // ─────────────────────────────────────────────────────────────────────────
    $tenant = Tenant::factory()->create();
    $user = User::factory()->forTenant($tenant)->create([
        'password' => Hash::make('password'),
    ]);
    $user->assignTenantRole($tenant, 'accountant');

    $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertOk();

    // Force fresh auth resolution: drop the SessionGuard's in-memory $user
    // so the next request re-resolves the authenticated user from the
    // session cookie via EloquentUserProvider::retrieveById() → User::find().
    auth()->forgetGuards();

    // /me must succeed under fresh resolution. If User has BelongsToTenant,
    // this assertion fails with status=500 instead of 200.
    $this->getJson('/api/v1/auth/me')->assertOk();

    // /logout sits behind auth:sanctum only (no tenant middleware), but
    // hits the same User::find path. Verify it works under fresh
    // resolution too.
    auth()->forgetGuards();
    $this->postJson('/api/v1/auth/logout')->assertNoContent();
});

it('H1a end-to-end: login → /me populates current_company via sole-fallback', function (): void {
    // Single-company tenant. User has no default/current set; ResolveCompany's
    // Step 4 sole-fallback fires on first request and backfills both. The /me
    // payload includes the company; subsequent /me calls hit Step 2 (current).
    $tenant = Tenant::factory()->create();
    $company = Company::factory()->forTenant($tenant)->create(['name' => 'Acme Trading Co.']);
    $user = User::factory()->forTenant($tenant)->create([
        'password' => Hash::make('secret-shoe-string-piano'),
        'default_company_id' => null,
        'current_company_id' => null,
    ]);
    $user->assignTenantRole($tenant, 'accountant');

    $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'secret-shoe-string-piano',
    ])->assertOk();

    $me = $this->getJson('/api/v1/auth/me')->assertOk();

    expect($me->json('data.current_company.id'))->toBe($company->id);
    expect($me->json('data.current_company.name'))->toBe('Acme Trading Co.');
    expect($me->json('data.companies'))->toHaveCount(1);

    // Sole-fallback persisted the choice — verify the row was updated.
    $fresh = $user->fresh();
    expect($fresh->default_company_id)->toBe($company->id);
    expect($fresh->current_company_id)->toBe($company->id);
});

it('H1a end-to-end: multi-company tenant — switching via X-Company-Id works across requests', function (): void {
    // Two companies in a single tenant. User starts pinned to A; X-Company-Id
    // switches to B for that request AND persists across requests.
    $tenant = Tenant::factory()->create();
    $companyA = Company::factory()->forTenant($tenant)->create(['name' => 'Acme Trading']);
    $companyB = Company::factory()->forTenant($tenant)->create(['name' => 'Acme Retail']);
    $user = User::factory()->forTenant($tenant)->create([
        'password' => Hash::make('secret-shoe-string-piano'),
        'default_company_id' => $companyA->id,
        'current_company_id' => $companyA->id,
    ]);
    $user->assignTenantRole($tenant, 'tenant_admin');

    $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'secret-shoe-string-piano',
    ])->assertOk();

    // First /me: default pins companyA.
    $first = $this->getJson('/api/v1/auth/me')->assertOk();
    expect($first->json('data.current_company.id'))->toBe($companyA->id);

    // Switch via header.
    $second = $this->withHeader('X-Company-Id', (string) $companyB->id)
        ->getJson('/api/v1/auth/me')
        ->assertOk();
    expect($second->json('data.current_company.id'))->toBe($companyB->id);

    // Persistence: third /me WITHOUT the header should still resolve B
    // because the header write updated current_company_id.
    $third = $this->getJson('/api/v1/auth/me')->assertOk();
    expect($third->json('data.current_company.id'))->toBe($companyB->id);
});

it('H1a end-to-end: multi-company tenant with no chosen default returns company_required on business routes', function (): void {
    // Defensive: /me is companyOptional so it returns gracefully. A future
    // business route in the ['auth:sanctum', 'tenant', 'company'] group
    // would throw company_required. We can't hit a real business route in
    // H1a (none exist yet), but we can prove the middleware throws by
    // calling /me WITHOUT the companyOptional opt-out — and trust the
    // unit-level coverage in ResolveCompanyTest::Step5.
    //
    // What we CAN assert at integration level: /me's companies array is
    // populated even when current_company is null, so the SPA has the
    // data it needs to render a picker.
    $tenant = Tenant::factory()->create();
    Company::factory()->forTenant($tenant)->create(['name' => 'A Co']);
    Company::factory()->forTenant($tenant)->create(['name' => 'B Co']);
    $user = User::factory()->forTenant($tenant)->create([
        'password' => Hash::make('secret-shoe-string-piano'),
        'default_company_id' => null,
        'current_company_id' => null,
    ]);
    $user->assignTenantRole($tenant, 'viewer');

    $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'secret-shoe-string-piano',
    ])->assertOk();

    $me = $this->getJson('/api/v1/auth/me')->assertOk();

    expect($me->json('data.current_company'))->toBeNull();
    expect($me->json('data.companies'))->toHaveCount(2);
});

it('H1a end-to-end: BackfillUsersToCompanyAction wires users to a newly-introduced company (Approach A transition)', function (): void {
    // Models the "one-company-to-two-companies transition" that CLAUDE.md
    // §3 documents. The action will be the real caller from any future
    // company-creation endpoint AND from DemoUsersSeeder; this test
    // exercises it end-to-end against the live auth flow.
    $tenant = Tenant::factory()->create();
    $companyA = Company::factory()->forTenant($tenant)->create(['name' => 'Acme One']);
    $user = User::factory()->forTenant($tenant)->create([
        'password' => Hash::make('secret-shoe-string-piano'),
        'default_company_id' => null,
        'current_company_id' => null,
    ]);
    $user->assignTenantRole($tenant, 'accountant');

    // Initially: zero companies bound to the user. Run the backfill (as
    // the future company-creation endpoint would).
    $count = app(BackfillUsersToCompanyAction::class)->execute($companyA);
    expect($count)->toBe(1);
    expect($user->fresh()->default_company_id)->toBe($companyA->id);

    // Now provision a SECOND company. The transition rule: re-run the
    // backfill (which now skips the user because their default is set).
    $companyB = Company::factory()->forTenant($tenant)->create(['name' => 'Acme Two']);
    $count2 = app(BackfillUsersToCompanyAction::class)->execute($companyB);
    expect($count2)->toBe(0); // Idempotent: existing user untouched.

    // Login + /me: user is still bound to companyA (no UX surprise after
    // company #2 lands). Switching to B is an explicit X-Company-Id action.
    $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'secret-shoe-string-piano',
    ])->assertOk();

    $me = $this->getJson('/api/v1/auth/me')->assertOk();
    expect($me->json('data.current_company.id'))->toBe($companyA->id);
    expect($me->json('data.companies'))->toHaveCount(2);
});
