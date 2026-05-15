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

use App\Models\Tenant;
use App\Models\User;
use App\Support\Audit\Models\AuditLog;
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
