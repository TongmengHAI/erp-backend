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

function rolesImpactAdmin(Tenant $tenant): User
{
    $admin = User::factory()->forTenant($tenant)->create();
    $admin->assignTenantRole($tenant, 'tenant_admin');

    return $admin;
}

function createImpactCustomRole(Tenant $tenant, string $name = 'Impact Role'): Role
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
    $role = Role::create([
        'name' => $name,
        'guard_name' => 'web',
        'team_id' => $tenant->id,
        'is_system' => false,
    ]);
    $role->syncPermissions(['hrm.employee.view', 'hrm.employee.create']);

    return $role;
}

it('LOAD-BEARING: returns affected_users_count matching assigned-user count', function (): void {
    $tenant = Tenant::factory()->create();
    $admin = rolesImpactAdmin($tenant);
    $role = createImpactCustomRole($tenant);

    // 4 users assigned this role.
    foreach (range(1, 4) as $i) {
        $u = User::factory()->forTenant($tenant)->create();
        $u->assignTenantRole($tenant, 'Impact Role');
    }

    $this->actingAs($admin);
    $response = $this->getJson("/api/v1/admin/roles/{$role->id}/impact?removed_permissions[]=hrm.employee.create");

    $response->assertOk();
    $response->assertJsonPath('data.affected_users_count', 4);
});

it('affected_users_preview returns up to 5 users sorted by name', function (): void {
    $tenant = Tenant::factory()->create();
    $admin = rolesImpactAdmin($tenant);
    $role = createImpactCustomRole($tenant);

    // 7 users — preview should cap at 5.
    foreach (['Zoe', 'Alice', 'Bob', 'Yuki', 'Charlie', 'Dave', 'Eve'] as $name) {
        $u = User::factory()->forTenant($tenant)->create(['name' => $name]);
        $u->assignTenantRole($tenant, 'Impact Role');
    }

    $this->actingAs($admin);
    $response = $this->getJson("/api/v1/admin/roles/{$role->id}/impact?removed_permissions[]=hrm.employee.create");

    $response->assertOk();
    $response->assertJsonPath('data.affected_users_count', 7);
    expect($response->json('data.affected_users_preview'))->toHaveCount(5);

    $previewNames = collect($response->json('data.affected_users_preview'))->pluck('name')->all();
    expect($previewNames)->toBe(['Alice', 'Bob', 'Charlie', 'Dave', 'Eve']);
});

it('returns zero count + empty preview when no users are assigned', function (): void {
    $tenant = Tenant::factory()->create();
    $admin = rolesImpactAdmin($tenant);
    $role = createImpactCustomRole($tenant);

    $this->actingAs($admin);
    $response = $this->getJson("/api/v1/admin/roles/{$role->id}/impact?removed_permissions[]=hrm.employee.create");

    $response->assertOk();
    $response->assertJsonPath('data.affected_users_count', 0);
    expect($response->json('data.affected_users_preview'))->toBe([]);
});

it('returns 401 when unauthenticated', function (): void {
    $tenant = Tenant::factory()->create();
    $role = createImpactCustomRole($tenant);

    $response = $this->getJson("/api/v1/admin/roles/{$role->id}/impact?removed_permissions[]=hrm.employee.create");
    $response->assertStatus(401);
});

it('returns 403 when actor has roles.view but not roles.update', function (): void {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->forTenant($tenant)->create();
    $user->assignTenantRole($tenant, 'viewer');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $role = createImpactCustomRole($tenant);

    $this->actingAs($user);
    $response = $this->getJson("/api/v1/admin/roles/{$role->id}/impact?removed_permissions[]=hrm.employee.create");
    $response->assertStatus(403);
});

it("returns 403 + error_code='system_role_immutable' when role is a system role", function (): void {
    $tenant = Tenant::factory()->create();
    $admin = rolesImpactAdmin($tenant);
    /** @var Role $systemRole */
    $systemRole = Role::system()->where('name', 'tenant_admin')->firstOrFail();

    $this->actingAs($admin);
    $response = $this->getJson("/api/v1/admin/roles/{$systemRole->id}/impact?removed_permissions[]=hrm.employee.view");

    $response->assertStatus(403);
    $response->assertJsonPath('error_code', 'system_role_immutable');
});

it('returns 422 when removed_permissions contains an unknown permission name', function (): void {
    $tenant = Tenant::factory()->create();
    $admin = rolesImpactAdmin($tenant);
    $role = createImpactCustomRole($tenant);

    $this->actingAs($admin);
    $response = $this->getJson("/api/v1/admin/roles/{$role->id}/impact?removed_permissions[]=fake.thing");
    $response->assertStatus(422);
});
