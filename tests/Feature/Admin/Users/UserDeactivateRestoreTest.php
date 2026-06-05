<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Support\Audit\Models\AuditLog;
use Database\Seeders\Framework\DefaultPermissionsSeeder;
use Database\Seeders\Framework\DefaultRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(DefaultPermissionsSeeder::class);
    $this->seed(DefaultRolesSeeder::class);
    $this->withHeader('Origin', 'http://localhost');
});

function deactivateRestoreAdmin(Tenant $tenant): User
{
    $admin = User::factory()->forTenant($tenant)->create();
    $admin->assignTenantRole($tenant, 'tenant_admin');

    return $admin;
}

it('deactivate: soft-deletes the target user', function (): void {
    $tenant = Tenant::factory()->create();
    $admin = deactivateRestoreAdmin($tenant);
    $target = User::factory()->forTenant($tenant)->create();

    $this->actingAs($admin);
    $response = $this->postJson("/api/v1/admin/users/{$target->id}/deactivate");

    $response->assertOk();
    $response->assertJsonPath('data.is_deactivated', true);
    expect(User::withTrashed()->find($target->id)->deleted_at)->not->toBeNull();
    expect(User::find($target->id))->toBeNull(); // default scope excludes
});

it('LOAD-BEARING: self-deactivate is blocked at the API with 403 error_code=self_action_forbidden', function (): void {
    // Phase 2A locked decision: API enforces independently of the UI.
    // Same shape as the self-disable LOAD-BEARING test — different
    // gate, same discipline. A future bug that nulls out one of these
    // guards fails its specific test loud.
    $tenant = Tenant::factory()->create();
    $admin = deactivateRestoreAdmin($tenant);

    $this->actingAs($admin);
    $response = $this->postJson("/api/v1/admin/users/{$admin->id}/deactivate");

    $response->assertStatus(403);
    $response->assertJsonPath('error_code', 'self_action_forbidden');
    $response->assertJsonPath('action', 'deactivate');

    // The admin row is NOT soft-deleted.
    expect($admin->fresh()->deleted_at)->toBeNull();
});

it("LOAD-BEARING: DeactivateUserAction writes an audit row with action='soft_deleted'", function (): void {
    // ─────────────────────────────────────────────────────────────────────
    // Action-name pin from Session 1: Auditable's writeAuditOnDeleted
    // handler emits action='soft_deleted' (NOT 'deleted') for SoftDeletes-
    // using models. That value was pinned at the trait layer in
    // UserAuditFlowTest. This test re-pins it at the ACTION layer — the
    // DeactivateUserAction's audit row must use the same 'soft_deleted'
    // value so any future Auditable refactor that changes the action
    // string fails BOTH tests (trait + action), and any Action-layer
    // bug that bypasses the trait (e.g. uses raw SQL or another delete
    // path) breaks this test independently of the trait test.
    // ─────────────────────────────────────────────────────────────────────
    $tenant = Tenant::factory()->create();
    $admin = deactivateRestoreAdmin($tenant);
    $target = User::factory()->forTenant($tenant)->create();

    $this->actingAs($admin);
    $this->postJson("/api/v1/admin/users/{$target->id}/deactivate")->assertOk();

    $row = AuditLog::query()
        ->where('auditable_type', User::class)
        ->where('auditable_id', $target->id)
        ->where('action', 'soft_deleted')
        ->latest('id')
        ->first();

    expect($row)->not->toBeNull();
    expect($row->actor_id)->toBe($admin->id);
});

it('deactivate: 404 cross-tenant target', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $admin = deactivateRestoreAdmin($tenantA);
    $targetInB = User::factory()->forTenant($tenantB)->create();

    $this->actingAs($admin);
    $response = $this->postJson("/api/v1/admin/users/{$targetInB->id}/deactivate");
    $response->assertStatus(404);
});

it('LOAD-BEARING regression: a deactivated user cannot log in', function (): void {
    $tenant = Tenant::factory()->create();
    $admin = deactivateRestoreAdmin($tenant);
    $target = User::factory()->forTenant($tenant)->create([
        'password' => bcrypt('correct-password'),
    ]);

    $this->actingAs($admin);
    $this->postJson("/api/v1/admin/users/{$target->id}/deactivate")->assertOk();
    auth('web')->logout();

    $loginResponse = $this->postJson('/api/v1/auth/login', [
        'email' => $target->email,
        'password' => 'correct-password',
    ]);

    $loginResponse->assertStatus(401);
    $loginResponse->assertJsonValidationErrors('email');
});

it('restore: brings a soft-deleted user back', function (): void {
    $tenant = Tenant::factory()->create();
    $admin = deactivateRestoreAdmin($tenant);
    $target = User::factory()->forTenant($tenant)->create();
    $target->delete();

    $this->actingAs($admin);
    $response = $this->postJson("/api/v1/admin/users/{$target->id}/restore");

    $response->assertOk();
    $response->assertJsonPath('data.is_deactivated', false);
    expect(User::find($target->id))->not->toBeNull();
    expect($target->fresh()->deleted_at)->toBeNull();
});

it('restore: writes an audit row with action=restored', function (): void {
    $tenant = Tenant::factory()->create();
    $admin = deactivateRestoreAdmin($tenant);
    $target = User::factory()->forTenant($tenant)->create();
    $target->delete();

    $this->actingAs($admin);
    $this->postJson("/api/v1/admin/users/{$target->id}/restore")->assertOk();

    $row = AuditLog::query()
        ->where('auditable_type', User::class)
        ->where('auditable_id', $target->id)
        ->where('action', 'restored')
        ->latest('id')
        ->first();

    expect($row)->not->toBeNull();
    expect($row->actor_id)->toBe($admin->id);
});

it('restore: 404 cross-tenant target', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $admin = deactivateRestoreAdmin($tenantA);
    $targetInB = User::factory()->forTenant($tenantB)->create();
    $targetInB->delete();

    $this->actingAs($admin);
    $response = $this->postJson("/api/v1/admin/users/{$targetInB->id}/restore");
    $response->assertStatus(404);
});
