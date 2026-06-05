<?php

declare(strict_types=1);

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

function showUpdateAdmin(Tenant $tenant): User
{
    $admin = User::factory()->forTenant($tenant)->create();
    $admin->assignTenantRole($tenant, 'tenant_admin');

    return $admin;
}

it('show: returns full AdminUserResource payload for a tenant user', function (): void {
    $tenant = Tenant::factory()->create();
    $admin = showUpdateAdmin($tenant);
    $target = User::factory()->forTenant($tenant)->create();
    $target->assignTenantRole($tenant, 'viewer');

    $this->actingAs($admin);
    $response = $this->getJson("/api/v1/admin/users/{$target->id}");

    $response->assertOk();
    $response->assertJsonPath('data.id', $target->id);
    $response->assertJsonPath('data.status', 'active');
    $response->assertJsonPath('data.is_deactivated', false);
    $response->assertJsonPath('data.role.name', 'viewer');
});

it('show: returns 404 when the target user belongs to a different tenant', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $admin = showUpdateAdmin($tenantA);
    $targetInB = User::factory()->forTenant($tenantB)->create();

    $this->actingAs($admin);
    $response = $this->getJson("/api/v1/admin/users/{$targetInB->id}");

    $response->assertStatus(404);
});

it('show: returns 404 when the target user does not exist', function (): void {
    $tenant = Tenant::factory()->create();
    $admin = showUpdateAdmin($tenant);

    $this->actingAs($admin);
    $response = $this->getJson('/api/v1/admin/users/999999');

    $response->assertStatus(404);
});

it('update: changes the name', function (): void {
    $tenant = Tenant::factory()->create();
    $admin = showUpdateAdmin($tenant);
    $target = User::factory()->forTenant($tenant)->create(['name' => 'Old Name']);

    $this->actingAs($admin);
    $response = $this->patchJson("/api/v1/admin/users/{$target->id}", ['name' => 'New Name']);

    $response->assertOk();
    $response->assertJsonPath('data.name', 'New Name');
    expect($target->fresh()->name)->toBe('New Name');
});

it('update: swaps the role from viewer to tenant_admin', function (): void {
    $tenant = Tenant::factory()->create();
    $admin = showUpdateAdmin($tenant);
    $target = User::factory()->forTenant($tenant)->create();
    $target->assignTenantRole($tenant, 'viewer');

    $adminRole = Role::findByName('tenant_admin', 'web');

    $this->actingAs($admin);
    $response = $this->patchJson("/api/v1/admin/users/{$target->id}", ['role_id' => $adminRole->id]);

    $response->assertOk();
    $response->assertJsonPath('data.role.name', 'tenant_admin');

    // Confirm the role was actually persisted at the team-scoped layer.
    setPermissionsTeamId($tenant->id);
    expect($target->fresh()->getRoleNames()->all())->toBe(['tenant_admin']);
});

it('update: 422 when role_id references a non-existent role', function (): void {
    $tenant = Tenant::factory()->create();
    $admin = showUpdateAdmin($tenant);
    $target = User::factory()->forTenant($tenant)->create();

    $this->actingAs($admin);
    $response = $this->patchJson("/api/v1/admin/users/{$target->id}", ['role_id' => 999999]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('role_id');
});

it('update: returns 404 for cross-tenant target', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $admin = showUpdateAdmin($tenantA);
    $targetInB = User::factory()->forTenant($tenantB)->create();

    $this->actingAs($admin);
    $response = $this->patchJson("/api/v1/admin/users/{$targetInB->id}", ['name' => 'Hacked']);

    $response->assertStatus(404);
});
