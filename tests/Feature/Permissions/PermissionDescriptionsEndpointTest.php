<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────────
// PermissionDescriptionsEndpointTest — Phase 2B Session 1.
//
// Standard 5-pattern on GET /api/v1/permissions/descriptions plus a
// per-permission coverage check.
//
// Coverage: every permission registered by DefaultPermissionsSeeder
// MUST have an entry in resources/lang/en/permissions.php under the
// permissions.permissions key. Missing entries would result in raw
// permission names rendering in the SPA (per Phase 2B Q18 "hidden
// from UI"). The test loops every seeded permission against the
// catalog so future permissions don't silently ship without a label.
// ─────────────────────────────────────────────────────────────────────────────

use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\Framework\DefaultPermissionsSeeder;
use Database\Seeders\Framework\DefaultRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(DefaultPermissionsSeeder::class);
    $this->seed(DefaultRolesSeeder::class);
    $this->withHeader('Origin', 'http://localhost');
});

it('returns 200 with both maps under data when authenticated', function (): void {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->forTenant($tenant)->create();
    $this->actingAs($user);

    $response = $this->getJson('/api/v1/permissions/descriptions');

    $response->assertOk();
    $response->assertJsonStructure([
        'data' => [
            'domains',
            'permissions',
        ],
    ]);

    expect($response->json('data.domains'))->toBeArray();
    expect($response->json('data.permissions'))->toBeArray();
    expect($response->json('data.domains.hrm'))->toBe('HRM');
    expect($response->json('data.domains.roles'))->toBe('Roles');
});

it('returns 401 when unauthenticated', function (): void {
    $response = $this->getJson('/api/v1/permissions/descriptions');
    $response->assertStatus(401);
});

it('LOAD-BEARING: every seeded permission has a description entry in the catalog', function (): void {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->forTenant($tenant)->create();
    $this->actingAs($user);

    $response = $this->getJson('/api/v1/permissions/descriptions');
    $response->assertOk();

    /** @var array<string, string> $catalog */
    $catalog = $response->json('data.permissions');

    $registered = Permission::query()->pluck('name')->all();

    $missing = [];
    foreach ($registered as $name) {
        if (! array_key_exists($name, $catalog)) {
            $missing[] = $name;
        }
    }

    expect(
        $missing,
        'Every permission in DefaultPermissionsSeeder must have a description '
            ."in resources/lang/en/permissions.php. Missing: \n  ".implode("\n  ", $missing)
    )->toBe([]);
});

it('LOAD-BEARING: every domain key referenced by a seeded permission has a domain label', function (): void {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->forTenant($tenant)->create();
    $this->actingAs($user);

    $response = $this->getJson('/api/v1/permissions/descriptions');
    /** @var array<string, string> $domains */
    $domains = $response->json('data.domains');

    $domainsUsed = collect(Permission::query()->pluck('name')->all())
        ->map(fn (string $n): string => explode('.', $n)[0])
        ->unique()
        ->values()
        ->all();

    $missing = [];
    foreach ($domainsUsed as $key) {
        if (! array_key_exists($key, $domains)) {
            $missing[] = $key;
        }
    }

    expect(
        $missing,
        'Every domain prefix used by a permission must have a domain label. '
            ."Missing: \n  ".implode("\n  ", $missing)
    )->toBe([]);
});
