<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────────
// UserUpdateRolesAssignGranularityTest — Phase 2B Session 2.
//
// The load-bearing behavior change of Phase 2B is the granularity
// split: roles.assign is SEPARATE from users.update. Phase 2A
// conflated them; Phase 2B splits. This test surface pins the
// boundary at the API layer.
//
// Tightening 1: the backend rejects ANY role_id in the PATCH body
// without roles.assign — even when role_id equals the user's current
// role. The FE's responsibility is to OMIT role_id from the body
// when the actor lacks roles.assign. Backend stays the single source
// of truth.
//
// Two LOAD-BEARING tests pin both halves of the granularity:
//   - 403 case: actor with users.update only, PATCH with role_id → 403
//     with error_code='missing_permission' + required_permission=
//     'roles.assign'
//   - 200 case: SAME actor, SAME endpoint, PATCH WITHOUT role_id → 200
//     success
//
// Both cases test the same actor on the same target user — only the
// presence of role_id in the body differs. That isolates the
// granularity behavior from any other auth dimension.
// ─────────────────────────────────────────────────────────────────────────────

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

/**
 * Create an admin with users.view + users.update but NOT roles.assign.
 * This is the actor shape that exercises the granularity boundary —
 * Phase 2A would have allowed the role change because users.update
 * implicitly covered it; Phase 2B rejects it because roles.assign is
 * SEPARATE.
 *
 * Uses a CUSTOM role (not tenant_admin) so the permission set is
 * surgical. Tenant_admin in this codebase gets all perms via the
 * auto-grant migration; a custom role with a precise subset is the
 * right test fixture for granularity.
 */
function granularityActor(Tenant $tenant): User
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
    $role = Role::create([
        'name' => 'User Editor (No Role Assign)',
        'guard_name' => 'web',
        'team_id' => $tenant->id,
        'is_system' => false,
    ]);
    // users.view + users.update only — NO roles.assign.
    $role->syncPermissions(['users.view', 'users.update']);

    $actor = User::factory()->forTenant($tenant)->create();
    $actor->assignTenantRole($tenant, 'User Editor (No Role Assign)');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $actor;
}

it("LOAD-BEARING: actor with users.update only, PATCH with role_id → 403 + error_code='missing_permission' + required_permission='roles.assign'", function (): void {
    $tenant = Tenant::factory()->create();
    $actor = granularityActor($tenant);
    $target = User::factory()->forTenant($tenant)->create(['name' => 'Original Name']);
    /** @var Role $newRole */
    $newRole = Role::system()->where('name', 'viewer')->firstOrFail();

    $this->actingAs($actor);
    $response = $this->patchJson("/api/v1/admin/users/{$target->id}", [
        'name' => 'Updated Name',
        'role_id' => $newRole->id,
    ]);

    $response->assertStatus(403);
    $response->assertJsonPath('error_code', 'missing_permission');
    $response->assertJsonPath('required_permission', 'roles.assign');

    // The name change did NOT persist either — the request was rejected
    // as a unit. The FE's responsibility is to send a clean PATCH.
    expect($target->fresh()->name)->toBe('Original Name');
});

it('LOAD-BEARING: SAME actor, SAME target, PATCH WITHOUT role_id → 200 success', function (): void {
    $tenant = Tenant::factory()->create();
    $actor = granularityActor($tenant);
    $target = User::factory()->forTenant($tenant)->create(['name' => 'Original Name']);

    $this->actingAs($actor);
    $response = $this->patchJson("/api/v1/admin/users/{$target->id}", [
        'name' => 'Updated Name',
    ]);

    $response->assertOk();
    expect($target->fresh()->name)->toBe('Updated Name');
});

it('Tightening 1: role_id is rejected even when it equals the current role', function (): void {
    // The backend doesn't look at the value — presence of the key in
    // the body is what triggers the gate. This pins Tightening 1
    // explicitly: a FE that submits the current role_id (because the
    // form serializes it) still gets 403. FE must OMIT the key.
    $tenant = Tenant::factory()->create();
    $actor = granularityActor($tenant);
    $target = User::factory()->forTenant($tenant)->create();
    /** @var Role $currentRole */
    $currentRole = Role::system()->where('name', 'viewer')->firstOrFail();
    $target->assignTenantRole($tenant, 'viewer');

    $this->actingAs($actor);
    $response = $this->patchJson("/api/v1/admin/users/{$target->id}", [
        'role_id' => $currentRole->id,
    ]);

    $response->assertStatus(403);
    $response->assertJsonPath('error_code', 'missing_permission');
});

it('actor WITH roles.assign succeeds at changing role', function (): void {
    // The positive control — confirms the gate isn't accidentally
    // rejecting everyone. tenant_admin has roles.assign per the
    // auto-grant seeder behavior.
    $tenant = Tenant::factory()->create();
    $admin = User::factory()->forTenant($tenant)->create();
    $admin->assignTenantRole($tenant, 'tenant_admin');
    $target = User::factory()->forTenant($tenant)->create();
    /** @var Role $accountant */
    $accountant = Role::system()->where('name', 'accountant')->firstOrFail();

    $this->actingAs($admin);
    $response = $this->patchJson("/api/v1/admin/users/{$target->id}", [
        'role_id' => $accountant->id,
    ]);

    $response->assertOk();
    expect($target->fresh()->roles->pluck('name')->all())->toBe(['accountant']);
});
