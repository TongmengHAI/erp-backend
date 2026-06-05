<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────────
// InvitationPermissionGatingTest — Phase 2A Session 2.
//
// LOAD-BEARING: every /admin/users/invitations/* route returns 404
// (NOT 403) for an authenticated user who lacks users.view. Same
// feature-hide convention as the user-lifecycle routes per §10.6.
// Loops over every endpoint so future additions inherit the gate
// (or fail this test loud if they forget).
//
// Also pins: the PUBLIC invitation routes are NOT gated by users.view
// — they're public by design (the invitee isn't signed up yet) but
// they DO require a valid token shape.
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

it('LOAD-BEARING: every admin /admin/users/invitations/* route returns 404 for a user without users.view', function (): void {
    $tenant = Tenant::factory()->create();
    $viewer = User::factory()->forTenant($tenant)->create();
    $viewer->assignTenantRole($tenant, 'viewer'); // no users.* perms

    // Create one invitation row so the {invitationId} routes have a
    // realistic id to bind against.
    $admin = User::factory()->forTenant($tenant)->create();
    $admin->assignTenantRole($tenant, 'tenant_admin');
    $role = Role::findByName('tenant_admin', 'web');
    $invitation = Invitation::query()->create([
        'tenant_id' => $tenant->id,
        'email' => 'invitee@example.com',
        'role_id' => $role->id,
        'token_hash' => Invitation::hashToken(Invitation::generateRawToken()),
        'invited_by_user_id' => $admin->id,
        'expires_at' => now()->addDays(7),
    ]);

    // Each route's POST body must pass field validation so the
    // controller's authorizeUsersAccess gate is the LAST one to fire.
    // Without this, the InviteUserRequest's `required` rules trigger a
    // 422 BEFORE the controller runs — masking the 404 gate from the
    // test. The empty body for cancel/resend is fine (no validation).
    $invitePayload = ['email' => 'fresh-invitee@example.com', 'role_id' => $role->id];
    $routes = [
        ['get', '/api/v1/admin/users/invitations', []],
        ['post', '/api/v1/admin/users/invitations', $invitePayload],
        ['post', "/api/v1/admin/users/invitations/{$invitation->id}/cancel", []],
        ['post', "/api/v1/admin/users/invitations/{$invitation->id}/resend", []],
    ];

    $this->actingAs($viewer);

    foreach ($routes as [$method, $url, $body]) {
        $response = match ($method) {
            'get' => $this->getJson($url),
            'post' => $this->postJson($url, $body),
        };
        expect(
            $response->getStatusCode(),
            "Expected 404 for viewer hitting {$method} {$url}, got {$response->getStatusCode()}"
        )->toBe(404);
    }
});

it('public invitation routes are NOT gated by auth — unauthenticated requests with valid token shape reach the controller', function (): void {
    // Confirms the public routes don't accidentally inherit the
    // auth:sanctum middleware. Unauthenticated GET with a non-existent
    // token returns 422 token_invalid (the controller's response), NOT
    // 401 (which would indicate an auth gate misfired into the public
    // route group).
    $bogus = str_repeat('a', 43);
    $response = $this->getJson("/api/v1/invitations/{$bogus}");
    $response->assertStatus(422);
    $response->assertJsonPath('error_code', 'token_invalid');
});
