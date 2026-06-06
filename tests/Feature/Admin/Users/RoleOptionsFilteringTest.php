<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────────
// RoleOptionsFilteringTest — Phase 2B Session 2.
//
// Tightening 2: GET /admin/users/role-options filters its returned
// row set by the actor's roles.assign permission.
//
//   - Actor with roles.assign → system + tenant's custom rows
//   - Actor without roles.assign → system rows only
//
// The response shape stays the same in both cases; only the row count
// differs. The frontend reads auth.can('roles.assign') for its own
// "Showing system roles only" copy.
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

function seedTenantCustomRoles(Tenant $tenant): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
    foreach (['Custom HR', 'Custom Accounting'] as $name) {
        Role::create([
            'name' => $name,
            'guard_name' => 'web',
            'team_id' => $tenant->id,
            'is_system' => false,
        ]);
    }
}

function actorWithUsersInviteOnly(Tenant $tenant): User
{
    // users.view + users.invite — NOT roles.assign.
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
    $role = Role::create([
        'name' => 'Inviter (No Role Assign)',
        'guard_name' => 'web',
        'team_id' => $tenant->id,
        'is_system' => false,
    ]);
    $role->syncPermissions(['users.view', 'users.invite']);

    $actor = User::factory()->forTenant($tenant)->create();
    $actor->assignTenantRole($tenant, 'Inviter (No Role Assign)');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $actor;
}

it('LOAD-BEARING: actor WITH roles.assign sees system + tenant custom rows', function (): void {
    $tenant = Tenant::factory()->create();
    seedTenantCustomRoles($tenant);
    $admin = User::factory()->forTenant($tenant)->create();
    $admin->assignTenantRole($tenant, 'tenant_admin'); // has roles.assign

    $this->actingAs($admin);
    $response = $this->getJson('/api/v1/admin/users/role-options');

    $response->assertOk();
    $names = collect($response->json('data'))->pluck('name')->all();
    expect($names)->toContain('tenant_admin', 'accountant', 'viewer');
    expect($names)->toContain('Custom HR', 'Custom Accounting');
});

it('LOAD-BEARING: actor WITHOUT roles.assign sees system rows only', function (): void {
    $tenant = Tenant::factory()->create();
    seedTenantCustomRoles($tenant);
    $actor = actorWithUsersInviteOnly($tenant);

    $this->actingAs($actor);
    $response = $this->getJson('/api/v1/admin/users/role-options');

    $response->assertOk();
    $rows = $response->json('data');
    $names = collect($rows)->pluck('name')->all();

    expect($names)->toContain('tenant_admin', 'accountant', 'viewer');
    expect($names)->not->toContain('Custom HR');
    expect($names)->not->toContain('Custom Accounting');
    expect($names)->not->toContain('Inviter (No Role Assign)');

    // Every returned row is a system row.
    collect($rows)->each(fn (array $r) => expect($r['is_system'])->toBeTrue());
});

it('actor with roles.assign in tenant A does not see tenant B custom rows', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    seedTenantCustomRoles($tenantB); // custom rows in B only
    $adminA = User::factory()->forTenant($tenantA)->create();
    $adminA->assignTenantRole($tenantA, 'tenant_admin');

    $this->actingAs($adminA);
    $response = $this->getJson('/api/v1/admin/users/role-options');

    $response->assertOk();
    $names = collect($response->json('data'))->pluck('name')->all();
    expect($names)->not->toContain('Custom HR');
    expect($names)->not->toContain('Custom Accounting');
});

it('returns 401 when unauthenticated', function (): void {
    $response = $this->getJson('/api/v1/admin/users/role-options');
    $response->assertStatus(401);
});

it('returns 404 when actor lacks users.view', function (): void {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->forTenant($tenant)->create();
    $user->assignTenantRole($tenant, 'viewer');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->actingAs($user);
    $response = $this->getJson('/api/v1/admin/users/role-options');
    $response->assertStatus(404);
});
