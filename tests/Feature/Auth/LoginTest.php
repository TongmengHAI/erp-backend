<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────────
// LoginTest — covers POST /api/v1/auth/login.
//
// §7.D pattern note: 403 (authorization failure) is N/A for the login endpoint.
// /api/v1/auth/login is the entry point used *before* any authorization
// context exists — there is no permission to deny against. The adjacent
// failure modes (user not found, wrong password, suspended tenant) are
// deliberately collapsed into a single generic 401 here to avoid information
// disclosure (see LoginController for the timing-attack mitigation rationale).
//
// Timing-parity test note: this file does NOT include an explicit "all three
// branches take the same wall-clock time" assertion. Wall-clock timing tests
// are flaky in CI (shared runners, varying bcrypt cost when BCRYPT_ROUNDS
// differs between envs, GC pauses). The security claim lives in the
// *structural* uniformity of LoginController — every failure branch runs
// the same operations in the same order. Tests below cover behavior; the
// timing claim is enforced by code structure + code review.
// ─────────────────────────────────────────────────────────────────────────────

use App\Models\Tenant;
use App\Models\User;
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

it('returns 401 with the same shape when the tenant is suspended (no info leak)', function (): void {
    $tenant = Tenant::factory()->suspended()->create();
    $user = User::factory()->forTenant($tenant)->create([
        'password' => Hash::make('correct-horse-battery-staple'),
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'correct-horse-battery-staple',
    ]);

    $response->assertStatus(401);
    $response->assertJsonValidationErrors('email');
    // Body must not hint that the tenant (vs. the credentials) was the problem.
    $body = $response->json();
    expect(json_encode($body))->not->toContain('suspended');
    expect(json_encode($body))->not->toContain('tenant');
    expect(auth('web')->check())->toBeFalse();
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
