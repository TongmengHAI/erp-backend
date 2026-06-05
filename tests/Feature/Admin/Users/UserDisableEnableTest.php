<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Support\Audit\Models\AuditLog;
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

function disableEnableAdmin(Tenant $tenant): User
{
    $admin = User::factory()->forTenant($tenant)->create();
    $admin->assignTenantRole($tenant, 'tenant_admin');

    return $admin;
}

it('disable: transitions an active user to inactive', function (): void {
    $tenant = Tenant::factory()->create();
    $admin = disableEnableAdmin($tenant);
    $target = User::factory()->forTenant($tenant)->create(['status' => UserStatus::Active]);

    $this->actingAs($admin);
    $response = $this->postJson("/api/v1/admin/users/{$target->id}/disable");

    $response->assertOk();
    $response->assertJsonPath('data.status', 'inactive');
    expect($target->fresh()->status)->toBe(UserStatus::Inactive);
});

it('LOAD-BEARING: self-disable is blocked at the API with 403 error_code=self_action_forbidden', function (): void {
    // Phase 2A locked decision: even if the UI somehow exposes the
    // self-disable affordance, the Action rejects with 403. Mirror's
    // §10.17 split-not-relax: the gate names itself + has its own
    // test so a future refactor can't silently drop it.
    $tenant = Tenant::factory()->create();
    $admin = disableEnableAdmin($tenant);

    $this->actingAs($admin);
    $response = $this->postJson("/api/v1/admin/users/{$admin->id}/disable");

    $response->assertStatus(403);
    $response->assertJsonPath('error_code', 'self_action_forbidden');
    $response->assertJsonPath('action', 'disable');

    // Critically: the admin's own row stays active.
    expect($admin->fresh()->status)->toBe(UserStatus::Active);
});

it('disable: writes an audit row with the status delta', function (): void {
    $tenant = Tenant::factory()->create();
    $admin = disableEnableAdmin($tenant);
    $target = User::factory()->forTenant($tenant)->create(['status' => UserStatus::Active]);

    $this->actingAs($admin);
    $this->postJson("/api/v1/admin/users/{$target->id}/disable")->assertOk();

    $row = AuditLog::query()
        ->where('auditable_type', User::class)
        ->where('auditable_id', $target->id)
        ->where('action', 'updated')
        ->latest('id')
        ->first();

    expect($row)->not->toBeNull();
    expect($row->actor_id)->toBe($admin->id);
    expect($row->before)->toHaveKey('status');
    expect($row->after['status'])->toBe('inactive');
});

it('disable: 404 cross-tenant target', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $admin = disableEnableAdmin($tenantA);
    $targetInB = User::factory()->forTenant($tenantB)->create();

    $this->actingAs($admin);
    $response = $this->postJson("/api/v1/admin/users/{$targetInB->id}/disable");
    $response->assertStatus(404);
});

it('enable: transitions an inactive user to active', function (): void {
    $tenant = Tenant::factory()->create();
    $admin = disableEnableAdmin($tenant);
    $target = User::factory()->forTenant($tenant)->inactive()->create();

    $this->actingAs($admin);
    $response = $this->postJson("/api/v1/admin/users/{$target->id}/enable");

    $response->assertOk();
    $response->assertJsonPath('data.status', 'active');
    expect($target->fresh()->status)->toBe(UserStatus::Active);
});

it('enable: idempotent — re-enabling an already-active user succeeds (no-op)', function (): void {
    $tenant = Tenant::factory()->create();
    $admin = disableEnableAdmin($tenant);
    $target = User::factory()->forTenant($tenant)->create(['status' => UserStatus::Active]);

    $this->actingAs($admin);
    $response = $this->postJson("/api/v1/admin/users/{$target->id}/enable");

    $response->assertOk();
    expect($target->fresh()->status)->toBe(UserStatus::Active);
});

it('LOAD-BEARING regression: a disabled user cannot log in', function (): void {
    // Composes with Session 1's $statusOk predicate boolean — pins the
    // user-facing flow end-to-end (disable via /admin/users/:id/disable
    // → subsequent login attempt returns 401). Per §10.19 we test the
    // flow not just the column.
    $tenant = Tenant::factory()->create();
    $admin = disableEnableAdmin($tenant);
    $target = User::factory()->forTenant($tenant)->create([
        'status' => UserStatus::Active,
        'password' => bcrypt('correct-password'),
    ]);

    $this->actingAs($admin);
    $this->postJson("/api/v1/admin/users/{$target->id}/disable")->assertOk();
    auth('web')->logout(); // clear actingAs session

    $loginResponse = $this->postJson('/api/v1/auth/login', [
        'email' => $target->email,
        'password' => 'correct-password',
    ]);

    $loginResponse->assertStatus(401);
    $loginResponse->assertJsonValidationErrors('email');
});
