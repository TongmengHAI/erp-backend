<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────────
// AcceptInvitationValidationTest — Phase 2A Session 2.
//
// Pins the AcceptInvitationRequest's password rules. Composition
// requirements (min(12), mixedCase, numbers, symbols) fire as 422 with
// errors.password populated. Per the locked-decision env note, the
// uncompromised() rule is intentionally not in the chain — see the
// AcceptInvitationRequest docblock.
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

function freshInvitationWithToken(): array
{
    $tenant = Tenant::factory()->create();
    $admin = User::factory()->forTenant($tenant)->create();
    $role = Role::findByName('tenant_admin', 'web');
    $rawToken = Invitation::generateRawToken();
    Invitation::query()->create([
        'tenant_id' => $tenant->id,
        'email' => 'invitee@example.com',
        'role_id' => $role->id,
        'token_hash' => Invitation::hashToken($rawToken),
        'invited_by_user_id' => $admin->id,
        'expires_at' => now()->addDays(7),
    ]);

    return [$tenant, $rawToken];
}

it('rejects passwords shorter than 12 characters', function (): void {
    [, $rawToken] = freshInvitationWithToken();

    $response = $this->postJson("/api/v1/invitations/{$rawToken}/accept", [
        'password' => 'Sh0rt!',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('password');
});

it('rejects passwords missing required character classes', function (): void {
    [, $rawToken] = freshInvitationWithToken();

    // Long enough but no uppercase, no number, no symbol.
    $response = $this->postJson("/api/v1/invitations/{$rawToken}/accept", [
        'password' => 'alllowercase12345',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('password');
});

it('accepts a password meeting all composition requirements', function (): void {
    [, $rawToken] = freshInvitationWithToken();

    $response = $this->postJson("/api/v1/invitations/{$rawToken}/accept", [
        'password' => 'V@lidP4ssw0rd-Strong-7',
    ]);

    $response->assertStatus(201);
});
