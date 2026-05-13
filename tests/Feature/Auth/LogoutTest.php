<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────────
// LogoutTest — covers POST /api/v1/auth/logout.
//
// §7.D pattern notes:
//   - 403 N/A: no permission concept at logout. If you're authenticated, you
//     can log out — there's nothing else to authorise against.
//   - 422 N/A: endpoint accepts no request body or query parameters.
//   - Cross-tenant isolation N/A at this layer: logout only mutates the
//     current session. Cross-session isolation is enforced by framework-level
//     session boundaries (HttpOnly cookies, session ID rotation on login),
//     not by anything this controller does.
// ─────────────────────────────────────────────────────────────────────────────

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Same Sanctum-stateful detection requirement as LoginTest / MeTest —
    // without Origin, EnsureFrontendRequestsAreStateful skips session middleware.
    $this->withHeader('Origin', 'http://localhost');
});

it('returns 204 No Content and clears the authenticated user on success', function (): void {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->forTenant($tenant)->create();

    $this->actingAs($user);

    $response = $this->postJson('/api/v1/auth/logout');

    $response->assertNoContent();
    expect(auth('web')->check())->toBeFalse();
});

it('returns 401 when called without an authenticated session', function (): void {
    $this->postJson('/api/v1/auth/logout')->assertStatus(401);
});

it('still succeeds when the user current tenant is suspended (no tenant middleware applied)', function (): void {
    // The whole point of putting /logout outside the `tenant` middleware:
    // a user whose tenant flips to suspended mid-session must still be able
    // to log out, not be trapped in a tenant_inactive 401 loop.
    $tenant = Tenant::factory()->suspended()->create();
    $user = User::factory()->forTenant($tenant)->create();

    $this->actingAs($user);

    $this->postJson('/api/v1/auth/logout')->assertNoContent();
    expect(auth('web')->check())->toBeFalse();
});

// Note: "after logout, subsequent requests are 401" is NOT tested here.
// Within a single Pest test, Laravel's TestCase keeps `actingAs()` state
// active even after a controller-side Auth::logout() — calling it tests
// Laravel's testing harness more than our controller. The "logout clears
// the guard" assertion in test #1 above (via auth('web')->check()) gives
// the same coverage in a direct, deterministic way. Production behaviour
// is fine: the session ID is destroyed server-side and the next HTTP
// request hits sanctum with no user.
