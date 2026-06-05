<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Support\Identity\Enums\UserStatus;
use Database\Seeders\Framework\DefaultPermissionsSeeder;
use Database\Seeders\Framework\DefaultRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(DefaultPermissionsSeeder::class);
    $this->seed(DefaultRolesSeeder::class);
    $this->withHeader('Origin', 'http://localhost');
});

function adminUserForTenant(Tenant $tenant): User
{
    $admin = User::factory()->forTenant($tenant)->create();
    $admin->assignTenantRole($tenant, 'tenant_admin');

    return $admin;
}

it('lists tenant users — happy path returns paginated brief resource', function (): void {
    $tenant = Tenant::factory()->create();
    $admin = adminUserForTenant($tenant);

    User::factory()->count(3)->forTenant($tenant)->create();

    $this->actingAs($admin);
    $response = $this->getJson('/api/v1/admin/users');

    $response->assertOk();
    $response->assertJsonStructure([
        'data' => [['id', 'name', 'email', 'status', 'role_name', 'is_active', 'is_deactivated']],
        'meta',
        'links',
    ]);

    // 4 users in this tenant (admin + 3 created)
    expect($response->json('meta.total'))->toBe(4);
});

it('isolates tenants — admin in tenant A sees only tenant A users in the list', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $admin = adminUserForTenant($tenantA);

    User::factory()->count(2)->forTenant($tenantA)->create(['name' => 'Tenant A User']);
    User::factory()->count(3)->forTenant($tenantB)->create(['name' => 'Tenant B User']);

    $this->actingAs($admin);
    $response = $this->getJson('/api/v1/admin/users');

    $response->assertOk();
    expect($response->json('meta.total'))->toBe(3); // admin + 2 from A; B's 3 excluded

    $names = collect($response->json('data'))->pluck('name')->all();
    expect($names)->not->toContain('Tenant B User');
});

it('filters by status — inactive query returns only inactive users', function (): void {
    $tenant = Tenant::factory()->create();
    $admin = adminUserForTenant($tenant);

    User::factory()->forTenant($tenant)->create(['status' => UserStatus::Active]);
    User::factory()->forTenant($tenant)->inactive()->create();
    User::factory()->forTenant($tenant)->inactive()->create();

    $this->actingAs($admin);
    $response = $this->getJson('/api/v1/admin/users?status=inactive');

    $response->assertOk();
    expect($response->json('meta.total'))->toBe(2);
    collect($response->json('data'))->each(
        fn (array $u) => expect($u['status'])->toBe('inactive')
    );
});

it('include_deactivated=true surfaces soft-deleted rows; default excludes them', function (): void {
    $tenant = Tenant::factory()->create();
    $admin = adminUserForTenant($tenant);

    $active = User::factory()->forTenant($tenant)->create();
    $deactivated = User::factory()->forTenant($tenant)->create();
    $deactivated->delete();

    $this->actingAs($admin);

    // Default — excludes deactivated.
    $response = $this->getJson('/api/v1/admin/users');
    $response->assertOk();
    expect($response->json('meta.total'))->toBe(2); // admin + active; deactivated excluded

    // With include_deactivated.
    $response = $this->getJson('/api/v1/admin/users?include_deactivated=1');
    $response->assertOk();
    expect($response->json('meta.total'))->toBe(3); // admin + active + deactivated
});

it('returns 422 when the status filter is not a valid UserStatus value', function (): void {
    $tenant = Tenant::factory()->create();
    $admin = adminUserForTenant($tenant);

    $this->actingAs($admin);
    $response = $this->getJson('/api/v1/admin/users?status=garbage');

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('status');
});
