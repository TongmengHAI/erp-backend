<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────────
// LoginTest — covers POST /api/v1/auth/login.
//
// §7.D pattern note: 403 (authorization failure) is N/A for the login
// endpoint. /api/v1/auth/login is the entry point used *before* any
// authorization context exists — there is no permission to deny against.
// The two real failure modes (user not found, wrong password) are
// deliberately collapsed into a single generic 401 here to defeat
// USER-ACCOUNT ENUMERATION (see LoginController for the timing-attack
// mitigation rationale).
//
// Suspended-tenant policy (Day 8): suspended-tenant users DO authenticate
// successfully. The /auth/me hop on the next request catches the suspension
// via ResolveTenant and returns 401 error_code=tenant_inactive, which the
// SPA route guard translates into a redirect to /tenant-suspended. See the
// suspended-tenant test below and the "SUSPENDED-TENANT POLICY" comment
// block in LoginController for full rationale.
//
// Timing-parity test note: this file does NOT include an explicit "both
// failure branches take the same wall-clock time" assertion. Wall-clock
// timing tests are flaky in CI (shared runners, varying bcrypt cost when
// BCRYPT_ROUNDS differs between envs, GC pauses). The security claim
// lives in the *structural* uniformity of LoginController — every failure
// branch runs the same operations in the same order. Tests below cover
// behavior; the timing claim is enforced by code structure + code review.
// ─────────────────────────────────────────────────────────────────────────────

use App\Models\Tenant;
use App\Models\User;
use App\Support\Identity\Enums\UserStatus;
use App\Support\Identity\Enums\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

// TestCase is bound to Feature/* by tests/Pest.php — only add RefreshDatabase here.
uses(RefreshDatabase::class);

beforeEach(function (): void {
    RateLimiter::clear('login');
    // Sanctum stateful detection keys on Origin/Referer matching SANCTUM_STATEFUL_DOMAINS.
    // Without this, EnsureFrontendRequestsAreStateful skips the session middleware
    // and $request->session()->regenerate() throws "Session store not set on request."
    $this->withHeader('Origin', 'http://localhost');
});

it('issues a session and returns the user payload on valid credentials', function (): void {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->forTenant($tenant)->create([
        'password' => Hash::make('correct-horse-battery-staple'),
        'current_tenant_id' => null,
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'correct-horse-battery-staple',
    ]);

    $response->assertOk();
    $response->assertJsonStructure([
        'data' => [
            'user' => ['id', 'name', 'email'],
            'tenant' => ['id', 'slug', 'name', 'default_currency', 'functional_currency', 'timezone'],
        ],
    ]);
    $response->assertJsonPath('data.user.id', $user->id);
    $response->assertJsonPath('data.tenant.id', $tenant->id);

    expect(auth('web')->id())->toBe($user->id);
    expect($user->fresh()->current_tenant_id)->toBe($tenant->id);
});

it('returns 401 with a generic message when the password is wrong', function (): void {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->forTenant($tenant)->create([
        'password' => Hash::make('correct-horse-battery-staple'),
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'wrong',
    ]);

    $response->assertStatus(401);
    $response->assertJsonValidationErrors('email');
    expect(auth('web')->check())->toBeFalse();
});

it('returns 401 with the same shape when the user does not exist', function (): void {
    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'nobody@example.com',
        'password' => 'anything',
    ]);

    $response->assertStatus(401);
    $response->assertJsonValidationErrors('email');
    expect(auth('web')->check())->toBeFalse();
});

it('authenticates a suspended-tenant user so the SPA can render /tenant-suspended', function (): void {
    // Day 8 policy: login succeeds for valid credentials even when the
    // user's tenant is suspended. The very next request (/auth/me here,
    // which is what the SPA fires after login() resolves) catches the
    // suspension via ResolveTenant and returns 401 error_code=tenant_inactive.
    // The SPA route guard reads that and redirects to /tenant-suspended.
    $tenant = Tenant::factory()->suspended()->create();
    $user = User::factory()->forTenant($tenant)->create([
        'password' => Hash::make('correct-horse-battery-staple'),
    ]);

    $loginResponse = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'correct-horse-battery-staple',
    ]);

    $loginResponse->assertOk();
    $loginResponse->assertJsonPath('data.user.id', $user->id);
    $loginResponse->assertJsonPath('data.tenant.id', $tenant->id);
    expect(auth('web')->id())->toBe($user->id);

    // The TenantResource intentionally does NOT include `status`, so the
    // wire response doesn't surface "suspended" to an attacker who pings
    // login with valid creds — they learn the creds are valid (which they
    // already learn for active tenants), nothing more about the org state.
    $body = $loginResponse->json();
    expect(json_encode($body))->not->toContain('suspended');

    // The follow-up /auth/me — what useAuthStore.login() calls after
    // authApi.login() resolves — is the gate that catches the suspension.
    $meResponse = $this->getJson('/api/v1/auth/me');
    $meResponse->assertStatus(401);
    $meResponse->assertJsonPath('error_code', 'tenant_inactive');
});

it('returns 422 when email or password is missing or malformed', function (): void {
    // Missing both
    $this->postJson('/api/v1/auth/login', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email', 'password']);

    // Malformed email
    $this->postJson('/api/v1/auth/login', [
        'email' => 'not-an-email',
        'password' => 'whatever',
    ])->assertStatus(422)->assertJsonValidationErrors('email');

    // Missing password only
    $this->postJson('/api/v1/auth/login', [
        'email' => 'user@example.com',
    ])->assertStatus(422)->assertJsonValidationErrors('password');
});

it('isolates tenants — user A in tenant 1 logs in and sees only tenant 1 in the response', function (): void {
    $tenant1 = Tenant::factory()->create(['name' => 'Tenant One']);
    $tenant2 = Tenant::factory()->create(['name' => 'Tenant Two']);
    $userA = User::factory()->forTenant($tenant1)->create([
        'password' => Hash::make('passA'),
    ]);
    User::factory()->forTenant($tenant2)->create([
        'password' => Hash::make('passB'),
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => $userA->email,
        'password' => 'passA',
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.tenant.id', $tenant1->id);
    $response->assertJsonPath('data.tenant.name', 'Tenant One');

    // The body must not leak tenant 2's name. (Don't substring-check tenant IDs:
    // small integers collide with timestamp fragments — brittle, not informative.)
    expect(json_encode($response->json()))->not->toContain('Tenant Two');
    expect(auth('web')->id())->toBe($userA->id);
});

it('returns 429 once the per-IP+email rate limit is exceeded', function (): void {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->forTenant($tenant)->create([
        'password' => Hash::make('right'),
    ]);

    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'wrong',
        ])->assertStatus(401);
    }

    $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'wrong',
    ])->assertStatus(429);
});

it('LOAD-BEARING: super admin (tenant_id=NULL) authenticates and the response carries tenant: null', function (): void {
    // SAs are vendor-side operators with NO tenant FK. The composite
    // users_super_admin_no_tenant_or_company_check DB constraint enforces
    // tenant_id IS NULL for type='super_admin'. The login path must treat
    // $tenant === null as the expected SA shape — not a broken FK.
    //
    // This test pins the contract: SA login succeeds, response.tenant is
    // literally null (not absent, not a tenant payload), and Auth::guard
    // is populated. A regression that re-introduces the "$tenant === null
    // → 401" predicate fails this test loudly.
    $sa = User::query()->create([
        'name' => 'Vendor Ops',
        'email' => 'ops@myerp.local',
        'password' => Hash::make('super-secret'),
        'email_verified_at' => now(),
        'type' => UserType::SuperAdmin,
        // tenant_id, current_tenant_id, default_company_id, current_company_id
        // all left null — the composite CHECK constraint demands it.
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => $sa->email,
        'password' => 'super-secret',
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.user.id', $sa->id);
    $response->assertJsonPath('data.user.is_super_admin', true);
    // tenant is literally null in the JSON body — distinguishable from
    // "missing key" by JsonPath matching against null.
    $response->assertJsonPath('data.tenant', null);
    expect(auth('web')->id())->toBe($sa->id);

    // current_tenant_id never gets a non-null write for an SA — the
    // composite CHECK constraint would reject it.
    expect($sa->fresh()->current_tenant_id)->toBeNull();
});

// ─────────────────────────────────────────────────────────────────────────────
// Predicate-isolation LOAD-BEARING tests (Phase 2A Session 1).
//
// LoginController's authentication predicate is a flat AND of four named
// booleans plus the user-exists short-circuit:
//
//   $user === null    || ! $passwordOk
//                     || ! $tenantOk     ($tenant !== null OR isSuperAdmin)
//                     || ! $statusOk     ($user->status === UserStatus::Active)
//                     || ! $notDeleted   ($user->deleted_at === null)
//
// Each test below sets up a user where ONLY ONE of those booleans fails;
// every other condition is at its passing value. A future regression that
// accidentally short-circuits one of the booleans fails that test specifically
// — the diagnostic surface is in the test name, not in the controller. Per
// §10.17 (split, not relax) and §10.19 (test the user-facing flow per gate).
//
// All four tests assert:
//   - HTTP 401
//   - Generic email-keyed validation error (auth.failed)
//   - auth('web')->check() is false (no session issued)
//
// Note on overlap with the existing 'wrong password' test below: that test
// asserts the response shape contract; the predicate-isolation test below
// asserts the gate-isolation invariant. Both stay — different concerns.
// ─────────────────────────────────────────────────────────────────────────────

it('LOAD-BEARING: $passwordOk gate fires independently — wrong password rejects an otherwise valid user', function (): void {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->forTenant($tenant)->create([
        'password' => Hash::make('right-password'),
        'status' => UserStatus::Active,
        // deleted_at left NULL by default — only $passwordOk should fail.
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $response->assertStatus(401);
    $response->assertJsonValidationErrors('email');
    expect(auth('web')->check())->toBeFalse();
});

it('LOAD-BEARING: $tenantOk gate fires independently — tenant_user whose tenant resolves to null rejects', function (): void {
    // Reach the $tenantOk = false branch for a non-SA user. The FK
    // constraint + composite users_super_admin_no_tenant_or_company_check
    // make "tenant_user with broken tenant_id" structurally unreachable;
    // the achievable failure mode is "tenant_user whose tenant_id points
    // to a SOFT-DELETED tenant" — Tenant::find() applies the SoftDeletes
    // default scope and returns null, so $tenant === null, no SA
    // exemption applies, $tenantOk fails.
    //
    // This is the same predicate boolean firing the same way as the
    // structurally-impossible "broken FK" case the controller comment
    // documents — both reach `Tenant::find() === null`. The test
    // confirms the gate fires; the regression it protects against is
    // a refactor that accidentally short-circuits $tenantOk.
    $tenant = Tenant::factory()->create();
    $user = User::factory()->forTenant($tenant)->create([
        'password' => Hash::make('correct-password'),
        'status' => UserStatus::Active,
    ]);

    // Sanity-check the discriminator: not a super_admin, so the SA
    // carve-out does not apply.
    expect($user->type)->toBe(UserType::TenantUser);

    // Soft-delete the tenant so Tenant::find($user->tenant_id) returns
    // null under the default scope, exactly mirroring the "broken FK"
    // branch the controller is defending against.
    $tenant->delete();
    expect(Tenant::find($tenant->id))->toBeNull();
    expect(Tenant::withTrashed()->find($tenant->id))->not->toBeNull();

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'correct-password',
    ]);

    $response->assertStatus(401);
    $response->assertJsonValidationErrors('email');
    expect(auth('web')->check())->toBeFalse();
});

it('LOAD-BEARING: $statusOk gate fires independently — status=inactive user rejects', function (): void {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->forTenant($tenant)->inactive()->create([
        'password' => Hash::make('correct-password'),
        // tenant_id valid, status=inactive (only failure), deleted_at NULL.
    ]);

    expect($user->status)->toBe(UserStatus::Inactive);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'correct-password',
    ]);

    $response->assertStatus(401);
    $response->assertJsonValidationErrors('email');
    expect(auth('web')->check())->toBeFalse();
});

it('LOAD-BEARING: $notDeleted gate fires independently — soft-deleted user rejects', function (): void {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->forTenant($tenant)->create([
        'password' => Hash::make('correct-password'),
        'status' => UserStatus::Active,
        // Soft-delete after create — deleted_at gets set, every other
        // condition stays at its passing value.
    ]);
    $user->delete();

    // Confirm the row is soft-deleted (not hard-deleted) — the
    // LoginController fetches via withTrashed() so the predicate can
    // see it and reject it via $notDeleted rather than silently
    // dropping into the generic $user === null branch.
    $fetched = User::withTrashed()->find($user->id);
    expect($fetched)->not->toBeNull();
    expect($fetched->deleted_at)->not->toBeNull();

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'correct-password',
    ]);

    $response->assertStatus(401);
    $response->assertJsonValidationErrors('email');
    expect(auth('web')->check())->toBeFalse();
});

it('the response payload shape matches { user, tenant } and excludes sensitive fields', function (): void {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->forTenant($tenant)->create([
        'password' => Hash::make('hunter2'),
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'hunter2',
    ]);

    $response->assertOk();
    $body = $response->json();

    expect(array_keys($body))->toBe(['data']);
    expect(array_keys($body['data']))->toEqualCanonicalizing(['user', 'tenant']);
    expect($body['data']['user'])->not->toHaveKey('password');
    expect($body['data']['user'])->not->toHaveKey('remember_token');
});
