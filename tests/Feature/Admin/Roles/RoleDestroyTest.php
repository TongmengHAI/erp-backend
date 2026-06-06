<?php

declare(strict_types=1);

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

function rolesDestroyAdmin(Tenant $tenant): User
{
    $admin = User::factory()->forTenant($tenant)->create();
    $admin->assignTenantRole($tenant, 'tenant_admin');

    return $admin;
}

function createDestroyCustomRole(Tenant $tenant, string $name = 'To Delete'): Role
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);

    return Role::create([
        'name' => $name,
        'guard_name' => 'web',
        'team_id' => $tenant->id,
        'is_system' => false,
    ]);
}

it('soft-deletes a custom role with no assigned users; returns 204', function (): void {
    $tenant = Tenant::factory()->create();
    $admin = rolesDestroyAdmin($tenant);
    $role = createDestroyCustomRole($tenant);

    $this->actingAs($admin);
    $response = $this->deleteJson("/api/v1/admin/roles/{$role->id}");

    $response->assertStatus(204);
    expect($role->fresh()->deleted_at)->not->toBeNull();
});

it('returns 401 when unauthenticated', function (): void {
    $tenant = Tenant::factory()->create();
    $role = createDestroyCustomRole($tenant);
    $response = $this->deleteJson("/api/v1/admin/roles/{$role->id}");
    $response->assertStatus(401);
});

it('returns 403 when actor has roles.view but not roles.delete', function (): void {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->forTenant($tenant)->create();
    $user->assignTenantRole($tenant, 'viewer');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $role = createDestroyCustomRole($tenant);

    $this->actingAs($user);
    $response = $this->deleteJson("/api/v1/admin/roles/{$role->id}");
    $response->assertStatus(403);
});

it("LOAD-BEARING: system role deletion returns 403 + error_code='system_role_immutable' across all 3 system roles", function (): void {
    $tenant = Tenant::factory()->create();
    $admin = rolesDestroyAdmin($tenant);

    $this->actingAs($admin);

    foreach (['tenant_admin', 'accountant', 'viewer'] as $systemRoleName) {
        /** @var Role $systemRole */
        $systemRole = Role::system()->where('name', $systemRoleName)->firstOrFail();
        $response = $this->deleteJson("/api/v1/admin/roles/{$systemRole->id}");

        $response->assertStatus(403);
        $response->assertJsonPath('error_code', 'system_role_immutable');
        $response->assertJsonPath('action', 'delete');

        // System row is not soft-deleted.
        expect($systemRole->fresh()->deleted_at)->toBeNull();
    }
});

it("LOAD-BEARING: deletion blocked by RoleInUseException returns 422 + error_code='role_in_use' + users_count", function (): void {
    // The full contract: error_code='role_in_use' AND users_count
    // must both be present. The FE reads users_count to render
    // "Cannot delete — N users are currently assigned to this role";
    // without the count, the message is unactionable.
    $tenant = Tenant::factory()->create();
    $admin = rolesDestroyAdmin($tenant);
    $role = createDestroyCustomRole($tenant);

    // 3 users assigned this role.
    foreach (range(1, 3) as $i) {
        $u = User::factory()->forTenant($tenant)->create();
        $u->assignTenantRole($tenant, 'To Delete');
    }

    $this->actingAs($admin);
    $response = $this->deleteJson("/api/v1/admin/roles/{$role->id}");

    $response->assertStatus(422);
    $response->assertJsonPath('error_code', 'role_in_use');
    $response->assertJsonPath('users_count', 3);

    // The role is NOT soft-deleted.
    expect($role->fresh()->deleted_at)->toBeNull();
});

it('users_count reflects the EXACT assigned count, not a sampled value', function (): void {
    $tenant = Tenant::factory()->create();
    $admin = rolesDestroyAdmin($tenant);
    $role = createDestroyCustomRole($tenant);

    foreach (range(1, 7) as $i) {
        $u = User::factory()->forTenant($tenant)->create();
        $u->assignTenantRole($tenant, 'To Delete');
    }

    $this->actingAs($admin);
    $response = $this->deleteJson("/api/v1/admin/roles/{$role->id}");

    $response->assertJsonPath('users_count', 7);
});

it('LOAD-BEARING: soft-deleted role is excluded from /admin/users/role-options', function (): void {
    $tenant = Tenant::factory()->create();
    $admin = rolesDestroyAdmin($tenant);
    $role = createDestroyCustomRole($tenant, 'Will Be Deleted');

    // Confirm it appears before deletion.
    $this->actingAs($admin);
    $before = $this->getJson('/api/v1/admin/users/role-options');
    $before->assertOk();
    $beforeNames = collect($before->json('data'))->pluck('name')->all();
    expect($beforeNames)->toContain('Will Be Deleted');

    // Delete and re-check.
    $this->deleteJson("/api/v1/admin/roles/{$role->id}")->assertStatus(204);

    $after = $this->getJson('/api/v1/admin/users/role-options');
    $after->assertOk();
    $afterNames = collect($after->json('data'))->pluck('name')->all();
    expect($afterNames)->not->toContain('Will Be Deleted');
});

it('returns 404 cross-tenant', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $adminA = rolesDestroyAdmin($tenantA);
    $roleB = createDestroyCustomRole($tenantB);

    $this->actingAs($adminA);
    $response = $this->deleteJson("/api/v1/admin/roles/{$roleB->id}");
    $response->assertStatus(404);
});
