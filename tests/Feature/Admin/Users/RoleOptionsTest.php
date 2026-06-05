<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\Framework\DefaultPermissionsSeeder;
use Database\Seeders\Framework\DefaultRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(DefaultPermissionsSeeder::class);
    $this->seed(DefaultRolesSeeder::class);
    $this->withHeader('Origin', 'http://localhost');
});

it('returns the seeded roles as id+name pairs ordered by name', function (): void {
    $tenant = Tenant::factory()->create();
    $admin = User::factory()->forTenant($tenant)->create();
    $admin->assignTenantRole($tenant, 'tenant_admin');

    $this->actingAs($admin);
    $response = $this->getJson('/api/v1/admin/users/role-options');

    $response->assertOk();
    $response->assertJsonStructure(['data' => [['id', 'name']]]);

    $names = collect($response->json('data'))->pluck('name')->all();
    expect($names)->toContain('tenant_admin', 'accountant', 'viewer');
    // Ordered by name (alphabetical) — keeps the SPA Select deterministic.
    $sorted = $names;
    sort($sorted);
    expect($names)->toBe($sorted);
});

it('returns 404 for a user without users.view (feature-hide convention)', function (): void {
    $tenant = Tenant::factory()->create();
    $viewer = User::factory()->forTenant($tenant)->create();
    $viewer->assignTenantRole($tenant, 'viewer');

    $this->actingAs($viewer);
    $response = $this->getJson('/api/v1/admin/users/role-options');

    $response->assertStatus(404);
});

it('returns 401 for unauthenticated requests', function (): void {
    $this->getJson('/api/v1/admin/users/role-options')->assertStatus(401);
});
