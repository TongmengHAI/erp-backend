<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────────
// SendInvitationEmailListenerTest — Phase 2B walk-fix.
//
// LOAD-BEARING regression test for the team_id-in-queued-job bug:
//
//   Spatie's findByParam (vendor/spatie/laravel-permission/src/Models/
//   Role.php:170) filters by team_id from the PermissionRegistrar:
//
//     WHERE team_id IS NULL OR team_id = <registrar_team_id>
//
//   In a queued job there is NO HTTP request → no middleware to set
//   the registrar's team_id → it is null → the second clause is FALSE
//   in SQL → only SYSTEM roles (team_id=NULL) are findable; CUSTOM
//   roles (team_id=$tenant_id) fall through with RoleDoesNotExist.
//
// Latent bug before Phase 2B: only system roles existed in Phase 2A,
// so the listener happened to work. Phase 2B Session 1's per-tenant
// custom roles activated the bug. The integration walk caught it.
//
// Fix: SendInvitationEmailListener now sets the PermissionRegistrar's
// team_id from invitation.tenant_id BEFORE the role lookup. This test
// exercises the CUSTOM-role path that would have thrown
// RoleDoesNotExist pre-fix. A subsequent regression that removes the
// fix fails this test loud.
//
// We exercise the listener directly (not via Event::fake on the wider
// flow) because the bug is specific to the queued-job-without-request
// shape — the test must NOT simulate a request context.
// ─────────────────────────────────────────────────────────────────────────────

use App\Domain\Identity\Events\UserInvited;
use App\Domain\Identity\Listeners\SendInvitationEmailListener;
use App\Domain\Identity\Models\Invitation;
use App\Domain\Identity\Models\Role;
use App\Mail\InvitationEmail;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\Framework\DefaultPermissionsSeeder;
use Database\Seeders\Framework\DefaultRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(DefaultPermissionsSeeder::class);
    $this->seed(DefaultRolesSeeder::class);
});

/**
 * Build a committed invitation referencing the given role for the given tenant.
 */
function makeInvitation(Tenant $tenant, User $inviter, Role $role): Invitation
{
    $rawToken = Str::random(43);

    /** @var Invitation $invitation */
    $invitation = Invitation::query()->create([
        'tenant_id' => $tenant->id,
        'email' => 'invitee@example.com',
        'name' => 'Test Invitee',
        'role_id' => $role->id,
        'token_hash' => hash('sha256', $rawToken),
        'invited_by_user_id' => $inviter->id,
        'expires_at' => now()->addDays(7),
    ]);

    return $invitation;
}

/**
 * Fire the listener handle() directly, simulating the queued-job-
 * without-request shape: no PermissionRegistrar team_id, no actingAs,
 * no middleware.
 */
function runListener(Invitation $invitation, string $rawToken): void
{
    // Clear any stale team_id from a previous test's tenant_admin
    // assignment via $admin->assignTenantRole(...). This is what the
    // queued job sees: no inherited registrar state.
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    /** @var SendInvitationEmailListener $listener */
    $listener = app(SendInvitationEmailListener::class);
    $listener->handle(new UserInvited($invitation, $rawToken));
}

it('LOAD-BEARING: queued-job listener sends the email when the invitation references a CUSTOM role (team_id=tenant_id)', function (): void {
    // The exact scenario the walk surfaced: admin invites a user with
    // a CUSTOM role selected. Pre-fix this threw RoleDoesNotExist
    // because the registrar's team_id was null in the queued job.
    Mail::fake();

    $tenant = Tenant::factory()->create();
    $admin = User::factory()->forTenant($tenant)->create();
    $admin->assignTenantRole($tenant, 'tenant_admin');

    // Create a tenant-scoped CUSTOM role.
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
    /** @var Role $customRole */
    $customRole = Role::create([
        'name' => 'Senior Accountant',
        'guard_name' => 'web',
        'team_id' => $tenant->id,
        'is_system' => false,
    ]);

    $rawToken = Str::random(43);
    $invitation = makeInvitation($tenant, $admin, $customRole);

    // Fire the listener WITHOUT a request context (registrar team_id
    // cleared inside runListener — matches the queued-job shape).
    runListener($invitation, $rawToken);

    Mail::assertSent(InvitationEmail::class, function (InvitationEmail $mail): bool {
        return $mail->hasTo('invitee@example.com');
    });
});

it('queued-job listener also sends the email for a SYSTEM role (positive control)', function (): void {
    // The pre-Phase-2B path: system roles (team_id=NULL) worked even
    // when the registrar's team_id was null because Spatie's findByParam
    // matches `whereNull(team_id)` first. This positive control proves
    // the fix didn't break the working system-role path.
    Mail::fake();

    $tenant = Tenant::factory()->create();
    $admin = User::factory()->forTenant($tenant)->create();
    $admin->assignTenantRole($tenant, 'tenant_admin');

    /** @var Role $systemRole */
    $systemRole = Role::system()->where('name', 'tenant_admin')->firstOrFail();

    $rawToken = Str::random(43);
    $invitation = makeInvitation($tenant, $admin, $systemRole);

    runListener($invitation, $rawToken);

    Mail::assertSent(InvitationEmail::class, function (InvitationEmail $mail): bool {
        return $mail->hasTo('invitee@example.com');
    });
});
