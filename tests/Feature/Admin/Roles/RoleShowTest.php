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

function rolesShowAdmin(Tenant $tenant): User
{
    $admin = User::factory()->forTenant($tenant)->create();
    $admin->assignTenantRole($tenant, 'tenant_admin');

    return $admin;
}

function createTenantCustomRole(Tenant $tenant, string $name = 'Custom Role'): Role
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);

    return Role::create([
        'name' => $name,
        'guard_name' => 'web',
        'team_id' => $tenant->id,
        'is_system' => false,
        'description' => 'A custom role for testing.',
    ]);
}

it('returns 200 with the full role payload including permissions array', function (): void {
    $tenant = Tenant::factory()->create();
    $admin = rolesShowAdmin($tenant);
    $role = createTenantCustomRole($tenant);
    $role->syncPermissions(['hrm.employee.view']);

    $this->actingAs($admin);
    $response = $this->getJson("/api/v1/admin/roles/{$role->id}");

    $response->assertOk();
    $response->assertJsonPath('data.id', $role->id);
    $response->assertJsonPath('data.name', 'Custom Role');
    $response->assertJsonPath('data.is_system', false);
    $response->assertJsonPath('data.description', 'A custom role for testing.');
    expect($response->json('data.permissions'))->toBeArray()->toHaveCount(1);
});

it('returns 200 on a system role with i18n label + description', function (): void {
    $tenant = Tenant::factory()->create();
    $admin = rolesShowAdmin($tenant);
    /** @var Role $systemRole */
    $systemRole = Role::system()->where('name', 'tenant_admin')->firstOrFail();

    $this->actingAs($admin);
    $response = $this->getJson("/api/v1/admin/roles/{$systemRole->id}");

    $response->assertOk();
    $response->assertJsonPath('data.is_system', true);
    $response->assertJsonPath('data.is_custom', false);
    $response->assertJsonPath('data.label', 'Tenant Administrator');
});

it('returns 401 when unauthenticated', function (): void {
    $tenant = Tenant::factory()->create();
    $role = createTenantCustomRole($tenant);

    $response = $this->getJson("/api/v1/admin/roles/{$role->id}");
    $response->assertStatus(401);
});

it('returns 404 when the actor lacks roles.view', function (): void {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->forTenant($tenant)->create();
    $user->assignTenantRole($tenant, 'viewer');
    Role::system()
        ->where('name', 'viewer')
        ->firstOrFail()
        ->revokePermissionTo('roles.view');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $role = createTenantCustomRole($tenant);

    $this->actingAs($user);
    $response = $this->getJson("/api/v1/admin/roles/{$role->id}");
    $response->assertStatus(404);
});

it('returns 404 when the role does not exist', function (): void {
    $tenant = Tenant::factory()->create();
    $admin = rolesShowAdmin($tenant);

    $this->actingAs($admin);
    $response = $this->getJson('/api/v1/admin/roles/999999');
    $response->assertStatus(404);
});

it('LOAD-BEARING: cross-tenant — Acme admin gets 404 on Sokha custom role', function (): void {
    $acme = Tenant::factory()->create(['name' => 'Acme']);
    $sokha = Tenant::factory()->create(['name' => 'Sokha']);
    $acmeAdmin = rolesShowAdmin($acme);
    $sokhaRole = createTenantCustomRole($sokha, 'Sokha Custom');

    $this->actingAs($acmeAdmin);
    $response = $this->getJson("/api/v1/admin/roles/{$sokhaRole->id}");

    $response->assertStatus(404);
});
