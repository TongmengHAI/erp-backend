<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────────
// InvitationConflictsTest — Phase 2A Session 2.
//
// LOAD-BEARING:
//   • email_globally_registered — inviting an email already a user in
//     ANY tenant returns 422 (Q10 Option A resolution; replaces the
//     original "email scoped per-tenant" test from the brief).
//   • active_invitation_exists — inviting an email with an existing
//     active invitation in the same tenant returns 422 with the
//     existing invitation_id surfaced (Q11).
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

it('LOAD-BEARING: inviting an email already registered as a user in ANOTHER tenant returns 422 email_globally_registered', function (): void {
    // Per Q10 Option A: users.email is GLOBALLY unique. Alice exists
    // in tenant A as a user; tenant B admin tries to invite her.
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    // Alice in tenant A — real user row.
    User::factory()->forTenant($tenantA)->create([
        'email' => 'alice@example.com',
        'name' => 'Alice in Tenant A',
    ]);

    // Bob is admin in tenant B and tries to invite Alice.
    $bobAdmin = User::factory()->forTenant($tenantB)->create();
    $bobAdmin->assignTenantRole($tenantB, 'tenant_admin');

    $role = Role::findByName('tenant_admin', 'web');

    $this->actingAs($bobAdmin);
    $response = $this->postJson('/api/v1/admin/users/invitations', [
        'email' => 'alice@example.com',
        'name' => 'Trying Alice',
        'role_id' => $role->id,
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('email');
    expect($response->json('errors.email.0'))
        ->toContain('already registered to another organization');

    // No invitation row was created in tenant B.
    expect(Invitation::query()->where('tenant_id', $tenantB->id)->count())->toBe(0);
});

it('email_globally_registered also fires for users that exist in the SAME tenant', function (): void {
    // Same gate — if Alice is already a user in tenant A, the admin
    // of tenant A can't "invite" her either. The error is about email
    // existing as a user, period.
    $tenant = Tenant::factory()->create();
    $admin = User::factory()->forTenant($tenant)->create();
    $admin->assignTenantRole($tenant, 'tenant_admin');
    User::factory()->forTenant($tenant)->create(['email' => 'alice@example.com']);

    $role = Role::findByName('tenant_admin', 'web');

    $this->actingAs($admin);
    $response = $this->postJson('/api/v1/admin/users/invitations', [
        'email' => 'alice@example.com',
        'role_id' => $role->id,
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('email');
});

it('email_globally_registered fires for soft-deleted users too (the UNIQUE constraint includes them)', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $alice = User::factory()->forTenant($tenantA)->create(['email' => 'alice@example.com']);
    $alice->delete(); // soft-delete

    $bobAdmin = User::factory()->forTenant($tenantB)->create();
    $bobAdmin->assignTenantRole($tenantB, 'tenant_admin');

    $role = Role::findByName('tenant_admin', 'web');

    $this->actingAs($bobAdmin);
    $response = $this->postJson('/api/v1/admin/users/invitations', [
        'email' => 'alice@example.com',
        'role_id' => $role->id,
    ]);

    $response->assertStatus(422);
    expect($response->json('errors.email.0'))
        ->toContain('already registered to another organization');
});

it('LOAD-BEARING: inviting an email with an active invitation in the same tenant returns 422 active_invitation_exists', function (): void {
    $tenant = Tenant::factory()->create();
    $admin = User::factory()->forTenant($tenant)->create();
    $admin->assignTenantRole($tenant, 'tenant_admin');
    $role = Role::findByName('tenant_admin', 'web');

    // First invitation — succeeds.
    $this->actingAs($admin);
    $first = $this->postJson('/api/v1/admin/users/invitations', [
        'email' => 'invitee@example.com',
        'role_id' => $role->id,
    ]);
    $first->assertStatus(201);

    // Second invitation for the SAME email in the SAME tenant — blocked.
    $second = $this->postJson('/api/v1/admin/users/invitations', [
        'email' => 'invitee@example.com',
        'role_id' => $role->id,
    ]);
    $second->assertStatus(422);
    $second->assertJsonValidationErrors('email');
    expect($second->json('errors.email.0'))
        ->toContain('active invitation already exists');
});

it('can re-invite an email after the prior invitation was cancelled', function (): void {
    // Cancellation moves the row out of the active-invitation partial
    // unique index's WHERE clause, so a fresh invitation is allowed.
    $tenant = Tenant::factory()->create();
    $admin = User::factory()->forTenant($tenant)->create();
    $admin->assignTenantRole($tenant, 'tenant_admin');
    $role = Role::findByName('tenant_admin', 'web');

    $this->actingAs($admin);
    $first = $this->postJson('/api/v1/admin/users/invitations', [
        'email' => 'invitee@example.com',
        'role_id' => $role->id,
    ]);
    $first->assertStatus(201);
    $firstInvitationId = $first->json('data.id');

    // Cancel it.
    $cancel = $this->postJson("/api/v1/admin/users/invitations/{$firstInvitationId}/cancel");
    $cancel->assertOk();

    // Re-invite — should succeed.
    $second = $this->postJson('/api/v1/admin/users/invitations', [
        'email' => 'invitee@example.com',
        'role_id' => $role->id,
    ]);
    $second->assertStatus(201);
});
