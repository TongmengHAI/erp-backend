<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────────
// InvitationFlowE2ETest — Phase 2A Session 2.
//
// LOAD-BEARING #1 from the original brief: full invitation lifecycle.
//   admin invites → invitation row exists with pending status →
//   UserInvited event dispatched → invitee fetches public preview →
//   invitee accepts with password → User created + auto-logged in →
//   subsequent login with new credentials succeeds.
//
// One test covers the whole happy path; if any link breaks, the test
// fails at the breaking step with a clear assertion message.
// ─────────────────────────────────────────────────────────────────────────────

use App\Domain\Identity\Events\UserInvited;
use App\Domain\Identity\Models\Invitation;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Identity\Enums\UserStatus;
use Database\Seeders\Framework\DefaultPermissionsSeeder;
use Database\Seeders\Framework\DefaultRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(DefaultPermissionsSeeder::class);
    $this->seed(DefaultRolesSeeder::class);
    $this->withHeader('Origin', 'http://localhost');
});

it('LOAD-BEARING: end-to-end — admin invites → token issued → invitee accepts → user created + auto-logged in → subsequent login works', function (): void {
    Event::fake([UserInvited::class]);

    $tenant = Tenant::factory()->create();
    $admin = User::factory()->forTenant($tenant)->create();
    $admin->assignTenantRole($tenant, 'tenant_admin');

    $role = Role::findByName('tenant_admin', 'web');

    // STEP 1 — admin invites.
    $this->actingAs($admin);
    $inviteResponse = $this->postJson('/api/v1/admin/users/invitations', [
        'email' => 'invitee@example.com',
        'name' => 'Test Invitee',
        'role_id' => $role->id,
    ]);
    $inviteResponse->assertStatus(201);
    $inviteResponse->assertJsonPath('data.email', 'invitee@example.com');
    $inviteResponse->assertJsonPath('data.status', 'pending');
    expect($inviteResponse->json('data'))->not->toHaveKey('token_hash');

    // STEP 2 — UserInvited dispatched with the invitation + raw token.
    Event::assertDispatched(UserInvited::class, function (UserInvited $event): bool {
        expect($event->invitation->email)->toBe('invitee@example.com');
        expect($event->rawToken)->toBeString();
        expect(strlen($event->rawToken))->toBe(43);

        return true;
    });

    // Capture the raw token from the dispatched event for STEP 3.
    $rawToken = null;
    Event::assertDispatched(UserInvited::class, function (UserInvited $event) use (&$rawToken): bool {
        $rawToken = $event->rawToken;

        return true;
    });
    expect($rawToken)->not->toBeNull();

    // Clear actingAs — invitee is unauthenticated for the public flow.
    auth('web')->logout();

    // STEP 3 — invitee fetches public preview.
    $previewResponse = $this->getJson("/api/v1/invitations/{$rawToken}");
    $previewResponse->assertOk();
    $previewResponse->assertJsonPath('data.email', 'invitee@example.com');
    $previewResponse->assertJsonPath('data.tenant.slug', $tenant->slug);
    $previewResponse->assertJsonPath('data.role_name', 'tenant_admin');
    $previewResponse->assertJsonPath('data.invited_by_name', $admin->name);

    // STEP 4 — invitee accepts with a strong password + optional name.
    $acceptResponse = $this->postJson("/api/v1/invitations/{$rawToken}/accept", [
        'password' => 'P@ssw0rd-VeryStrong-789!',
        'name' => 'Invitee Self-Named',
    ]);
    $acceptResponse->assertStatus(201);
    $acceptResponse->assertJsonPath('data.user.email', 'invitee@example.com');
    $acceptResponse->assertJsonPath('data.tenant.id', $tenant->id);

    // STEP 5 — invitee is auto-logged in (Sanctum session active).
    expect(auth('web')->check())->toBeTrue();
    $createdUser = User::query()->where('email', 'invitee@example.com')->firstOrFail();
    expect(auth('web')->id())->toBe($createdUser->id);
    expect($createdUser->tenant_id)->toBe($tenant->id);
    expect($createdUser->status)->toBe(UserStatus::Active);

    // STEP 6 — invitation row marked accepted with FK back to user.
    $invitation = Invitation::query()->where('email', 'invitee@example.com')->firstOrFail();
    expect($invitation->accepted_at)->not->toBeNull();
    expect($invitation->accepted_user_id)->toBe($createdUser->id);

    // STEP 7 — logout, flush session + cookies (the auto-login left
    // session state that interferes with a clean subsequent login),
    // then log back in with new credentials.
    auth('web')->logout();
    $this->flushSession();

    // Sanity: Hash::check confirms the stored password matches the
    // plaintext we set during accept.
    $fresh = $createdUser->fresh();
    expect(Hash::check('P@ssw0rd-VeryStrong-789!', $fresh->password))->toBeTrue();
    expect($fresh->status)->toBe(UserStatus::Active);
    expect($fresh->deleted_at)->toBeNull();
    expect($fresh->tenant_id)->toBe($tenant->id);
    expect(Tenant::find($fresh->tenant_id))->not->toBeNull();
    RateLimiter::clear('login');

    $loginResponse = $this->postJson('/api/v1/auth/login', [
        'email' => 'invitee@example.com',
        'password' => 'P@ssw0rd-VeryStrong-789!',
    ]);
    $loginResponse->assertOk();
    $loginResponse->assertJsonPath('data.user.id', $createdUser->id);
});
