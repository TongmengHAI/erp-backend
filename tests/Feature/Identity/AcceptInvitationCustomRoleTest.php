<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────────
// AcceptInvitationCustomRoleTest — Phase 2B walk-fix.
//
// LOAD-BEARING regression test: invitee accepts an invitation whose
// invitation.role_id references a CUSTOM role (team_id=$tenant_id).
//
// The accept endpoint is PUBLIC (no auth → no middleware → no team_id
// resolution). Spatie's findByParam filters
//   WHERE team_id IS NULL OR team_id = <registrar_team_id>
// and the second clause is FALSE when team_id is null. Pre-walk-fix
// the action threw RoleDoesNotExist for any custom-role acceptance —
// the integration walk caught it.
//
// Same shape and same fix as the SendInvitationEmailListener walk-fix
// (commit b91ba97). The action now sets the registrar's team_id from
// invitation.tenant_id before findById.
//
// This test exercises the FULL accept-endpoint path so any regression
// that removes the fix fails at the HTTP boundary.
// ─────────────────────────────────────────────────────────────────────────────

use App\Domain\Identity\Models\Invitation;
use App\Domain\Identity\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\Framework\DefaultPermissionsSeeder;
use Database\Seeders\Framework\DefaultRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(DefaultPermissionsSeeder::class);
    $this->seed(DefaultRolesSeeder::class);
    $this->withHeader('Origin', 'http://localhost');
});

it('LOAD-BEARING: invitee accepts invitation that references a CUSTOM role (team_id=tenant_id) — User created + role assigned + 201', function (): void {
    // The exact scenario the walk surfaced.
    $tenant = Tenant::factory()->create();
    $admin = User::factory()->forTenant($tenant)->create();
    $admin->assignTenantRole($tenant, 'tenant_admin');

    // Create a tenant-scoped CUSTOM role.
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
    /** @var Role $customRole */
    $customRole = Role::create([
        'name' => 'Junior Accountant',
        'guard_name' => 'web',
        'team_id' => $tenant->id,
        'is_system' => false,
    ]);

    $rawToken = Invitation::generateRawToken();
    Invitation::query()->create([
        'tenant_id' => $tenant->id,
        'email' => 'invitee@example.com',
        'name' => 'Custom Invitee',
        'role_id' => $customRole->id,
        'token_hash' => Invitation::hashToken($rawToken),
        'invited_by_user_id' => $admin->id,
        'expires_at' => now()->addDays(7),
    ]);

    // Clear the registrar so the accept endpoint genuinely starts
    // without a team_id — matches the public-endpoint shape.
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    // Public endpoint — no actingAs.
    $response = $this->postJson("/api/v1/invitations/{$rawToken}/accept", [
        'password' => 'V@lidStrongPass123',
        'name' => 'Custom Invitee',
    ]);

    $response->assertStatus(201);
    $response->assertJsonPath('data.user.email', 'invitee@example.com');

    // The user exists and was assigned the custom role on the right
    // team (Spatie's HasTenantRoles wrapper sets team_id correctly).
    /** @var User $user */
    $user = User::query()->where('email', 'invitee@example.com')->firstOrFail();
    expect($user->tenant_id)->toBe($tenant->id);

    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
    expect($user->fresh()->roles->pluck('name')->all())->toContain('Junior Accountant');
});

it('positive control: invitee accepts invitation that references a SYSTEM role — still works post-fix', function (): void {
    // The pre-Phase-2B path: system roles (team_id=NULL) worked even
    // without the registrar set. This test proves the fix didn't break
    // the system-role acceptance.
    $tenant = Tenant::factory()->create();
    $admin = User::factory()->forTenant($tenant)->create();
    $admin->assignTenantRole($tenant, 'tenant_admin');

    /** @var Role $systemRole */
    $systemRole = Role::system()->where('name', 'accountant')->firstOrFail();

    $rawToken = Invitation::generateRawToken();
    Invitation::query()->create([
        'tenant_id' => $tenant->id,
        'email' => 'sys-invitee@example.com',
        'name' => 'System Invitee',
        'role_id' => $systemRole->id,
        'token_hash' => Invitation::hashToken($rawToken),
        'invited_by_user_id' => $admin->id,
        'expires_at' => now()->addDays(7),
    ]);

    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    $response = $this->postJson("/api/v1/invitations/{$rawToken}/accept", [
        'password' => 'V@lidStrongPass123',
        'name' => 'System Invitee',
    ]);

    $response->assertStatus(201);

    /** @var User $user */
    $user = User::query()->where('email', 'sys-invitee@example.com')->firstOrFail();
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
    expect($user->fresh()->roles->pluck('name')->all())->toContain('accountant');
});
