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

function rolesIndexAdmin(Tenant $tenant): User
{
    $admin = User::factory()->forTenant($tenant)->create();
    $admin->assignTenantRole($tenant, 'tenant_admin');

    return $admin;
}

it('returns 200 with system rows + tenant custom rows under data', function (): void {
    $tenant = Tenant::factory()->create();
    $admin = rolesIndexAdmin($tenant);

    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
    Role::create([
        'name' => 'Senior Accountant',
        'guard_name' => 'web',
        'team_id' => $tenant->id,
        'is_system' => false,
    ]);

    $this->actingAs($admin);
    $response = $this->getJson('/api/v1/admin/roles');

    $response->assertOk();
    $response->assertJsonStructure([
        'data' => [['id', 'name', 'label', 'is_system', 'is_custom', 'users_count']],
        'meta',
        'links',
    ]);

    // 3 system + 1 custom = 4 rows.
    expect($response->json('meta.total'))->toBe(4);
});

it('returns 401 when unauthenticated', function (): void {
    $response = $this->getJson('/api/v1/admin/roles');
    $response->assertStatus(401);
});

it('returns 404 when the actor lacks roles.view (non-admin)', function (): void {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->forTenant($tenant)->create();
    $user->assignTenantRole($tenant, 'viewer');
    // Strip viewer's roles.view to simulate a future role that doesn't
    // grant it — clean test of the gate without depending on the
    // shipped viewer permission set. Revoking from the SYSTEM viewer
    // role directly (RefreshDatabase isolates the change to this test).
    Role::system()
        ->where('name', 'viewer')
        ->firstOrFail()
        ->revokePermissionTo('roles.view');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->actingAs($user);
    $response = $this->getJson('/api/v1/admin/roles');
    $response->assertStatus(404);
});

it('kind=system filter returns only system rows', function (): void {
    $tenant = Tenant::factory()->create();
    $admin = rolesIndexAdmin($tenant);

    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
    Role::create([
        'name' => 'A Custom',
        'guard_name' => 'web',
        'team_id' => $tenant->id,
        'is_system' => false,
    ]);

    $this->actingAs($admin);
    $response = $this->getJson('/api/v1/admin/roles?kind=system');

    $response->assertOk();
    expect($response->json('meta.total'))->toBe(3);
    collect($response->json('data'))->each(
        fn (array $r) => expect($r['is_system'])->toBeTrue()
    );
});

it('kind=custom filter returns only custom rows', function (): void {
    $tenant = Tenant::factory()->create();
    $admin = rolesIndexAdmin($tenant);

    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
    Role::create([
        'name' => 'Custom A',
        'guard_name' => 'web',
        'team_id' => $tenant->id,
        'is_system' => false,
    ]);

    $this->actingAs($admin);
    $response = $this->getJson('/api/v1/admin/roles?kind=custom');

    $response->assertOk();
    expect($response->json('meta.total'))->toBe(1);
});

it('isolates tenants — admin in tenant A does not see tenant B custom roles', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $admin = rolesIndexAdmin($tenantA);

    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    Role::create([
        'name' => 'Tenant B Only',
        'guard_name' => 'web',
        'team_id' => $tenantB->id,
        'is_system' => false,
    ]);

    $this->actingAs($admin);
    $response = $this->getJson('/api/v1/admin/roles?kind=custom');

    $response->assertOk();
    expect($response->json('meta.total'))->toBe(0);
    $names = collect($response->json('data'))->pluck('name')->all();
    expect($names)->not->toContain('Tenant B Only');
});
