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

function rolesUpdateAdmin(Tenant $tenant): User
{
    $admin = User::factory()->forTenant($tenant)->create();
    $admin->assignTenantRole($tenant, 'tenant_admin');

    return $admin;
}

function createUpdateTargetRole(Tenant $tenant, string $name = 'Editable Role'): Role
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
    $role = Role::create([
        'name' => $name,
        'guard_name' => 'web',
        'team_id' => $tenant->id,
        'is_system' => false,
        'description' => 'Original',
    ]);
    $role->syncPermissions(['hrm.employee.view', 'hrm.employee.create']);

    return $role;
}

it('updates name + description + permissions; returns 200 with the refreshed payload', function (): void {
    $tenant = Tenant::factory()->create();
    $admin = rolesUpdateAdmin($tenant);
    $role = createUpdateTargetRole($tenant);

    $newPermIds = Permission::whereIn('name', ['hrm.employee.view'])->pluck('id')->all();

    $this->actingAs($admin);
    $response = $this->patchJson("/api/v1/admin/roles/{$role->id}", [
        'name' => 'Renamed Role',
        'description' => 'Updated description',
        'permission_ids' => $newPermIds,
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.name', 'Renamed Role');
    $response->assertJsonPath('data.description', 'Updated description');
    expect($role->fresh()->permissions->pluck('name')->all())->toBe(['hrm.employee.view']);
});

it('returns 401 when unauthenticated', function (): void {
    $tenant = Tenant::factory()->create();
    $role = createUpdateTargetRole($tenant);
    $response = $this->patchJson("/api/v1/admin/roles/{$role->id}", ['name' => 'X']);
    $response->assertStatus(401);
});

it('returns 403 when actor has roles.view but not roles.update', function (): void {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->forTenant($tenant)->create();
    $user->assignTenantRole($tenant, 'viewer');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $role = createUpdateTargetRole($tenant);

    $this->actingAs($user);
    $response = $this->patchJson("/api/v1/admin/roles/{$role->id}", ['name' => 'X']);
    $response->assertStatus(403);
});

it('returns 422 when permission_ids contains an invalid id', function (): void {
    $tenant = Tenant::factory()->create();
    $admin = rolesUpdateAdmin($tenant);
    $role = createUpdateTargetRole($tenant);

    $this->actingAs($admin);
    $response = $this->patchJson("/api/v1/admin/roles/{$role->id}", [
        'permission_ids' => [99999],
    ]);
    $response->assertStatus(422);
});

it("LOAD-BEARING: system role mutation returns 403 + error_code='system_role_immutable' across all 3 system roles", function (): void {
    $tenant = Tenant::factory()->create();
    $admin = rolesUpdateAdmin($tenant);

    $this->actingAs($admin);

    foreach (['tenant_admin', 'accountant', 'viewer'] as $systemRoleName) {
        /** @var Role $systemRole */
        $systemRole = Role::system()->where('name', $systemRoleName)->firstOrFail();

        // PATCH description (avoids the FormRequest's notIn(systemNames)
        // check on name — we want to exercise the Action-layer guard).
        $response = $this->patchJson("/api/v1/admin/roles/{$systemRole->id}", [
            'description' => 'Hacker description',
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('error_code', 'system_role_immutable');
        $response->assertJsonPath('action', 'update');
    }
});

it('LOAD-BEARING: removing a permission writes per-affected-user audit rows', function (): void {
    // Per CLAUDE.md §10.20 (defense-in-depth via per-layer audit
    // signal) — when permissions are revoked via a role update, every
    // user currently assigned that role gets an audit row recording
    // which permissions they lost. This is the explicit user-visible
    // half of the audit trail; the role's own 'updated' audit row is
    // the other half.
    $tenant = Tenant::factory()->create();
    $admin = rolesUpdateAdmin($tenant);
    $role = createUpdateTargetRole($tenant);

    // Three users assigned this role.
    $assignee1 = User::factory()->forTenant($tenant)->create(['name' => 'Alice']);
    $assignee2 = User::factory()->forTenant($tenant)->create(['name' => 'Bob']);
    $assignee3 = User::factory()->forTenant($tenant)->create(['name' => 'Carol']);
    foreach ([$assignee1, $assignee2, $assignee3] as $u) {
        $u->assignTenantRole($tenant, 'Editable Role');
    }

    // Update — remove 'hrm.employee.create' (was in the role's permissions).
    $remainingIds = Permission::whereIn('name', ['hrm.employee.view'])->pluck('id')->all();

    $this->actingAs($admin);
    $this->patchJson("/api/v1/admin/roles/{$role->id}", [
        'permission_ids' => $remainingIds,
    ])->assertOk();

    foreach ([$assignee1, $assignee2, $assignee3] as $u) {
        $row = AuditLog::query()
            ->where('auditable_type', User::class)
            ->where('auditable_id', $u->id)
            ->where('action', 'permissions_revoked_via_role')
            ->latest('id')
            ->first();

        expect($row)->not->toBeNull("user {$u->name} missing audit row");
        expect($row->before['permissions'])->toContain('hrm.employee.create');
        expect($row->before['role_id'])->toBe($role->id);
        expect($row->before['role_name'])->toBe('Editable Role');
        expect($row->actor_id)->toBe($admin->id);
    }
});

it('removing a permission writes the per-user audit even when only one user is assigned', function (): void {
    $tenant = Tenant::factory()->create();
    $admin = rolesUpdateAdmin($tenant);
    $role = createUpdateTargetRole($tenant);

    $assignee = User::factory()->forTenant($tenant)->create();
    $assignee->assignTenantRole($tenant, 'Editable Role');

    $remainingIds = Permission::whereIn('name', ['hrm.employee.view'])->pluck('id')->all();

    $this->actingAs($admin);
    $this->patchJson("/api/v1/admin/roles/{$role->id}", [
        'permission_ids' => $remainingIds,
    ])->assertOk();

    $count = AuditLog::query()
        ->where('auditable_type', User::class)
        ->where('action', 'permissions_revoked_via_role')
        ->count();
    expect($count)->toBe(1);
});

it('does NOT write per-user audit rows when no permissions are removed (additive change)', function (): void {
    $tenant = Tenant::factory()->create();
    $admin = rolesUpdateAdmin($tenant);
    $role = createUpdateTargetRole($tenant);

    $assignee = User::factory()->forTenant($tenant)->create();
    $assignee->assignTenantRole($tenant, 'Editable Role');

    // ADD a permission (no removal).
    $allIds = Permission::whereIn('name', [
        'hrm.employee.view', 'hrm.employee.create', 'hrm.employee.update',
    ])->pluck('id')->all();

    $this->actingAs($admin);
    $this->patchJson("/api/v1/admin/roles/{$role->id}", [
        'permission_ids' => $allIds,
    ])->assertOk();

    $count = AuditLog::query()
        ->where('auditable_type', User::class)
        ->where('action', 'permissions_revoked_via_role')
        ->count();
    expect($count)->toBe(0);
});

it('returns 404 cross-tenant', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $adminA = rolesUpdateAdmin($tenantA);
    $roleB = createUpdateTargetRole($tenantB);

    $this->actingAs($adminA);
    $response = $this->patchJson("/api/v1/admin/roles/{$roleB->id}", ['name' => 'X']);
    $response->assertStatus(404);
});
