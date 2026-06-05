<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────────
// InvitationLifecycleTest — Phase 2A Session 2.
//
// LOAD-BEARING:
//   • Token expiry → 422 expired
//   • Re-send invalidates old token → 422 token_invalid on old URL
//   • Cancelled invitation token → 422 cancelled
//   • Already-accepted token → 422 accepted
// ─────────────────────────────────────────────────────────────────────────────

use App\Domain\Identity\Models\Invitation;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\Framework\DefaultPermissionsSeeder;
use Database\Seeders\Framework\DefaultRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(DefaultPermissionsSeeder::class);
    $this->seed(DefaultRolesSeeder::class);
    $this->withHeader('Origin', 'http://localhost');
});

function lifecycleSetup(): array
{
    $tenant = Tenant::factory()->create();
    $admin = User::factory()->forTenant($tenant)->create();
    $admin->assignTenantRole($tenant, 'tenant_admin');
    $role = Role::findByName('tenant_admin', 'web');

    return [$tenant, $admin, $role];
}

function createInvitationWithToken(Tenant $tenant, User $admin, Role $role): array
{
    $rawToken = Invitation::generateRawToken();
    $invitation = Invitation::query()->create([
        'tenant_id' => $tenant->id,
        'email' => 'invitee@example.com',
        'name' => 'Test Invitee',
        'role_id' => $role->id,
        'token_hash' => Invitation::hashToken($rawToken),
        'invited_by_user_id' => $admin->id,
        'expires_at' => now()->addDays(7),
    ]);

    return [$invitation, $rawToken];
}

it('LOAD-BEARING: an expired invitation returns 422 with error_code=expired', function (): void {
    [$tenant, $admin, $role] = lifecycleSetup();
    [$invitation, $rawToken] = createInvitationWithToken($tenant, $admin, $role);

    // Move the clock — set expires_at to the past directly.
    $invitation->expires_at = now()->subDay();
    $invitation->save();

    $response = $this->postJson("/api/v1/invitations/{$rawToken}/accept", [
        'password' => 'P@ssw0rd-VeryStrong-789!',
    ]);

    $response->assertStatus(422);
    $response->assertJsonPath('error_code', 'expired');
    // No user was created.
    expect(User::query()->where('email', 'invitee@example.com')->exists())->toBeFalse();
});

it('LOAD-BEARING: re-sending an invitation invalidates the previous token', function (): void {
    [$tenant, $admin, $role] = lifecycleSetup();
    [$original, $originalRawToken] = createInvitationWithToken($tenant, $admin, $role);

    // Resend via the admin endpoint. The new invitation is a fresh
    // resource — the old row was soft-deleted and a NEW row created —
    // so 201 is the semantically correct status (matches the store
    // endpoint's behavior).
    $this->actingAs($admin);
    $resendResponse = $this->postJson("/api/v1/admin/users/invitations/{$original->id}/resend");
    $resendResponse->assertStatus(201);

    // The old token is now invalid — the original row is soft-deleted;
    // its hash isn't in the active row set anymore.
    auth('web')->logout();
    $oldAcceptResponse = $this->postJson("/api/v1/invitations/{$originalRawToken}/accept", [
        'password' => 'P@ssw0rd-VeryStrong-789!',
    ]);
    $oldAcceptResponse->assertStatus(422);
    $oldAcceptResponse->assertJsonPath('error_code', 'token_invalid');

    // And exactly one active invitation row exists post-resend.
    $activeCount = Invitation::query()
        ->where('tenant_id', $tenant->id)
        ->where('email', 'invitee@example.com')
        ->count();
    expect($activeCount)->toBe(1);
});

it('LOAD-BEARING: a cancelled invitation token returns 422 with error_code=cancelled', function (): void {
    [$tenant, $admin, $role] = lifecycleSetup();
    [$invitation, $rawToken] = createInvitationWithToken($tenant, $admin, $role);

    $this->actingAs($admin);
    $cancelResponse = $this->postJson("/api/v1/admin/users/invitations/{$invitation->id}/cancel");
    $cancelResponse->assertOk();
    $cancelResponse->assertJsonPath('data.status', 'cancelled');

    auth('web')->logout();
    $acceptResponse = $this->postJson("/api/v1/invitations/{$rawToken}/accept", [
        'password' => 'P@ssw0rd-VeryStrong-789!',
    ]);
    $acceptResponse->assertStatus(422);
    $acceptResponse->assertJsonPath('error_code', 'cancelled');

    // No user created.
    expect(User::query()->where('email', 'invitee@example.com')->exists())->toBeFalse();
});

it('an already-accepted invitation token returns 422 with error_code=accepted on re-use', function (): void {
    [$tenant, $admin, $role] = lifecycleSetup();
    [, $rawToken] = createInvitationWithToken($tenant, $admin, $role);

    // Accept the first time.
    $firstAccept = $this->postJson("/api/v1/invitations/{$rawToken}/accept", [
        'password' => 'P@ssw0rd-VeryStrong-789!',
    ]);
    $firstAccept->assertStatus(201);

    // Second attempt on the same token.
    auth('web')->logout();
    $secondAccept = $this->postJson("/api/v1/invitations/{$rawToken}/accept", [
        'password' => 'D!fferentP4ssw0rd-XYZ!',
    ]);
    $secondAccept->assertStatus(422);
    $secondAccept->assertJsonPath('error_code', 'accepted');
});

it('a malformed token (wrong length / non-base64) returns 404 from the route constraint', function (): void {
    lifecycleSetup();

    // 10 chars — fails the {43} constraint.
    $response = $this->getJson('/api/v1/invitations/tooshort');
    $response->assertStatus(404);

    // Contains symbols not in [A-Za-z0-9].
    $response2 = $this->getJson('/api/v1/invitations/'.str_repeat('A', 43).'!@#');
    $response2->assertStatus(404);
});

it('a well-formed but non-existent token returns 422 token_invalid', function (): void {
    lifecycleSetup();

    // 43-char string that won't match any row.
    $bogus = str_repeat('a', 43);
    $response = $this->getJson("/api/v1/invitations/{$bogus}");
    $response->assertStatus(422);
    $response->assertJsonPath('error_code', 'token_invalid');
});
