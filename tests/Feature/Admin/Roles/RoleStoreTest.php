<?php

declare(strict_types=1);

use App\Domain\Identity\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Audit\Models\AuditLog;
use Database\Seeders\Framework\DefaultPermissionsSeeder;
use Database\Seeders\Framework\DefaultRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(DefaultPermissionsSeeder::class);
    $this->seed(DefaultRolesSeeder::class);
    $this->withHeader('Origin', 'http://localhost');
});

function rolesStoreAdmin(Tenant $tenant): User
{
    $admin = User::factory()->forTenant($tenant)->create();
    $admin->assignTenantRole($tenant, 'tenant_admin');

    return $admin;
}

function permissionIds(array $names): array
{
    return Permission::whereIn('name', $names)->pluck('id')->all();
}

it('creates a custom role with name + description + permissions; returns 201 with full payload', function (): void {
    $tenant = Tenant::factory()->create();
    $admin = rolesStoreAdmin($tenant);
    $permIds = permissionIds(['hrm.employee.view', 'hrm.employee.create']);

    $this->actingAs($admin);
    $response = $this->postJson('/api/v1/admin/roles', [
        'name' => 'HR Lead',
        'description' => 'Manages HR ops',
        'permission_ids' => $permIds,
    ]);

    $response->assertStatus(201);
    $response->assertJsonPath('data.name', 'HR Lead');
    $response->assertJsonPath('data.description', 'Manages HR ops');
    $response->assertJsonPath('data.is_system', false);
    $response->assertJsonPath('data.is_custom', true);

    $created = Role::custom()->where('name', 'HR Lead')->where('team_id', $tenant->id)->first();
    expect($created)->not->toBeNull();
    expect($created->permissions->pluck('name')->all())
        ->toContain('hrm.employee.view', 'hrm.employee.create');
});

it('returns 401 when unauthenticated', function (): void {
    $response = $this->postJson('/api/v1/admin/roles', ['name' => 'X', 'permission_ids' => []]);
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

    $this->actingAs($user);
    $response = $this->postJson('/api/v1/admin/roles', [
        'name' => 'X', 'permission_ids' => [],
    ]);
    $response->assertStatus(404);
});

it('returns 403 when actor has roles.view but not roles.create', function (): void {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->forTenant($tenant)->create();
    $user->assignTenantRole($tenant, 'viewer'); // viewer has roles.view, not roles.create
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->actingAs($user);
    $response = $this->postJson('/api/v1/admin/roles', [
        'name' => 'X', 'permission_ids' => [],
    ]);
    $response->assertStatus(403);
});

it('returns 422 when name is missing', function (): void {
    $tenant = Tenant::factory()->create();
    $admin = rolesStoreAdmin($tenant);

    $this->actingAs($admin);
    $response = $this->postJson('/api/v1/admin/roles', ['permission_ids' => []]);
    $response->assertStatus(422);
    $response->assertJsonValidationErrors('name');
});

it("LOAD-BEARING: rejects name='tenant_admin' (system role name collision) at validation", function (): void {
    // The application-layer guard. The DB's partial indexes don't fire
    // here because the system roles have team_id=NULL while custom
    // rows have team_id=$tenant_id — they don't collide at the DB
    // layer. FormRequest is the canonical guard.
    $tenant = Tenant::factory()->create();
    $admin = rolesStoreAdmin($tenant);

    $this->actingAs($admin);

    foreach (['tenant_admin', 'accountant', 'viewer'] as $reserved) {
        $response = $this->postJson('/api/v1/admin/roles', [
            'name' => $reserved,
            'permission_ids' => [],
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors('name');
    }
});

it('LOAD-BEARING: custom-role-per-tenant uniqueness rejected at validation (same tenant)', function (): void {
    $tenant = Tenant::factory()->create();
    $admin = rolesStoreAdmin($tenant);

    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
    Role::create([
        'name' => 'Senior Accountant',
        'guard_name' => 'web',
        'team_id' => $tenant->id,
        'is_system' => false,
    ]);

    $this->actingAs($admin);
    $response = $this->postJson('/api/v1/admin/roles', [
        'name' => 'Senior Accountant',
        'permission_ids' => [],
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('name');
});

it('LOAD-BEARING: custom-role-per-tenant uniqueness allows reuse across tenants', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $adminA = rolesStoreAdmin($tenantA);
    $adminB = rolesStoreAdmin($tenantB);

    $this->actingAs($adminA);
    $this->postJson('/api/v1/admin/roles', [
        'name' => 'Senior Accountant',
        'permission_ids' => [],
    ])->assertStatus(201);

    $this->actingAs($adminB);
    $this->postJson('/api/v1/admin/roles', [
        'name' => 'Senior Accountant',
        'permission_ids' => [],
    ])->assertStatus(201);

    // Two rows now exist, one per tenant.
    expect(Role::custom()->where('name', 'Senior Accountant')->count())->toBe(2);
});

it('returns 422 when permission_ids contains an invalid id', function (): void {
    $tenant = Tenant::factory()->create();
    $admin = rolesStoreAdmin($tenant);

    $this->actingAs($admin);
    $response = $this->postJson('/api/v1/admin/roles', [
        'name' => 'X', 'permission_ids' => [99999],
    ]);
    $response->assertStatus(422);
    $response->assertJsonValidationErrors('permission_ids.0');
});

it('writes an audit row with action=created on the new role', function (): void {
    $tenant = Tenant::factory()->create();
    $admin = rolesStoreAdmin($tenant);

    $this->actingAs($admin);
    $this->postJson('/api/v1/admin/roles', [
        'name' => 'AuditedRole',
        'permission_ids' => [],
    ])->assertStatus(201);

    $created = Role::where('name', 'AuditedRole')->firstOrFail();
    $row = AuditLog::query()
        ->where('auditable_type', Role::class)
        ->where('auditable_id', $created->id)
        ->where('action', 'created')
        ->latest('id')
        ->first();
    expect($row)->not->toBeNull();
    expect($row->actor_id)->toBe($admin->id);
});
