<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Support\Identity\Enums\UserType;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    // Create a few global (team_id=null) roles + a permission for the trait tests.
    // Production roles are created the same way by DefaultRolesSeeder.
    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId(null);

    Permission::firstOrCreate(['name' => 'accounting.journal_entry.view']);

    Role::firstOrCreate(['name' => 'accountant', 'team_id' => null])
        ->givePermissionTo('accounting.journal_entry.view');
    Role::firstOrCreate(['name' => 'viewer', 'team_id' => null]);

    $registrar->forgetCachedPermissions();
});

it('assignTenantRole creates an assignment row scoped to the given tenant', function (): void {
    $tenantA = Tenant::factory()->create();
    $user = User::factory()->forTenant($tenantA)->create();

    $user->assignTenantRole($tenantA, 'accountant');

    // Verify the model_has_roles row carries team_id = tenantA.id
    expect(DB::table(config('permission.table_names.model_has_roles'))
        ->where('model_id', $user->id)
        ->where('team_id', $tenantA->id)
        ->exists())->toBeTrue();
});

it('assignTenantRole restores the registrar team_id after the call', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $user = User::factory()->forTenant($tenantA)->create();

    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($tenantB->id);

    $user->assignTenantRole($tenantA, 'accountant');

    expect($registrar->getPermissionsTeamId())->toBe($tenantB->id);
});

it('belongsToTenant returns true after assignTenantRole', function (): void {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->forTenant($tenant)->create();

    expect($user->belongsToTenant($tenant))->toBeFalse();

    $user->assignTenantRole($tenant, 'accountant');

    expect($user->fresh()->belongsToTenant($tenant))->toBeTrue();
});

it('belongsToTenant returns false for a tenant where the user has no role rows', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $user = User::factory()->forTenant($tenantA)->create();

    $user->assignTenantRole($tenantA, 'accountant');

    expect($user->belongsToTenant($tenantB))->toBeFalse();
});

it('hasRole respects Spatie teams scoping — a role in tenant A is invisible in tenant B', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $user = User::factory()->forTenant($tenantA)->create();

    $user->assignTenantRole($tenantA, 'accountant');

    $registrar = app(PermissionRegistrar::class);

    $registrar->setPermissionsTeamId($tenantA->id);
    expect($user->fresh()->hasRole('accountant'))->toBeTrue();

    $registrar->setPermissionsTeamId($tenantB->id);
    expect($user->fresh()->hasRole('accountant'))->toBeFalse();
});

it('defaultTenant returns the user home tenant', function (): void {
    $home = Tenant::factory()->create();
    $user = User::factory()->forTenant($home)->create();

    expect($user->defaultTenant()?->id)->toBe($home->id);
});

it('defaultTenant returns null when the user has no home tenant', function (): void {
    // Same shape as the orphan-user case in ResolveTenantTest: the
    // composite DB CHECK 'users_tenant_user_has_tenant_check' (Session 1)
    // makes a persisted tenant_user with NULL tenant_id unreachable.
    // defaultTenant()'s null-safe branch still exists as a runtime guard;
    // we exercise it by constructing the User in-memory without persisting.
    $user = new User;
    $user->tenant_id = null;
    $user->type = UserType::TenantUser;

    expect($user->defaultTenant())->toBeNull();
});
