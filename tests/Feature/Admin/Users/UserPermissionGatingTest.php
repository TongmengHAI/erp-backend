<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────────
// UserPermissionGatingTest — Phase 2A Session 2.
//
// LOAD-BEARING permission gating: every /admin/users/* route returns
// 404 (NOT 403) for an authenticated user who lacks users.view. The
// 404 convention matches §10.6 (mirrors SuperAdminGuard's gate-as-
// feature-hide); the feature effectively does not exist for them.
//
// Loops over EVERY admin/users route in one test so that a future
// endpoint added under the same prefix automatically inherits the gate
// — or fails this test loud if it doesn't.
// ─────────────────────────────────────────────────────────────────────────────

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

it('LOAD-BEARING: every /admin/users/* route returns 404 for a user without users.view', function (): void {
    $tenant = Tenant::factory()->create();
    $viewer = User::factory()->forTenant($tenant)->create();
    $viewer->assignTenantRole($tenant, 'viewer'); // viewer has NO users.* per Phase 2A

    $target = User::factory()->forTenant($tenant)->create();

    // Each tuple: HTTP method, route. Loop catches future endpoint
    // additions that forget the gate.
    $routes = [
        ['get', '/api/v1/admin/users'],
        ['get', "/api/v1/admin/users/{$target->id}"],
        ['patch', "/api/v1/admin/users/{$target->id}"],
        ['post', "/api/v1/admin/users/{$target->id}/disable"],
        ['post', "/api/v1/admin/users/{$target->id}/enable"],
        ['post', "/api/v1/admin/users/{$target->id}/deactivate"],
        ['post', "/api/v1/admin/users/{$target->id}/restore"],
    ];

    $this->actingAs($viewer);

    foreach ($routes as [$method, $url]) {
        $response = match ($method) {
            'get' => $this->getJson($url),
            'patch' => $this->patchJson($url, []),
            'post' => $this->postJson($url, []),
        };

        expect(
            $response->getStatusCode(),
            "Expected 404 for viewer hitting {$method} {$url}, got {$response->getStatusCode()}"
        )->toBe(404);
    }
});

it('returns 401 for unauthenticated requests on every /admin/users/* route', function (): void {
    $tenant = Tenant::factory()->create();
    $target = User::factory()->forTenant($tenant)->create();

    $routes = [
        ['get', '/api/v1/admin/users'],
        ['get', "/api/v1/admin/users/{$target->id}"],
        ['patch', "/api/v1/admin/users/{$target->id}"],
        ['post', "/api/v1/admin/users/{$target->id}/disable"],
        ['post', "/api/v1/admin/users/{$target->id}/enable"],
        ['post', "/api/v1/admin/users/{$target->id}/deactivate"],
        ['post', "/api/v1/admin/users/{$target->id}/restore"],
    ];

    foreach ($routes as [$method, $url]) {
        $response = match ($method) {
            'get' => $this->getJson($url),
            'patch' => $this->patchJson($url, []),
            'post' => $this->postJson($url, []),
        };

        expect(
            $response->getStatusCode(),
            "Expected 401 for unauthenticated {$method} {$url}, got {$response->getStatusCode()}"
        )->toBe(401);
    }
});
