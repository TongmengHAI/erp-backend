<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────────
// InvitationListTest — Phase 2A Session 2.
//
// Standard endpoint coverage for GET /api/v1/admin/users/invitations:
// happy + tenant isolation + status filter via InvitationQueryService's
// SQL CASE WHEN (mirrors the model accessor).
// ─────────────────────────────────────────────────────────────────────────────

use App\Domain\Identity\Models\Invitation;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\Framework\DefaultPermissionsSeeder;
use Database\Seeders\Framework\DefaultRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(DefaultPermissionsSeeder::class);
    $this->seed(DefaultRolesSeeder::class);
    $this->withHeader('Origin', 'http://localhost');
});

function listAdmin(Tenant $tenant): User
{
    $admin = User::factory()->forTenant($tenant)->create();
    $admin->assignTenantRole($tenant, 'tenant_admin');

    return $admin;
}

it('lists invitations for the current tenant only', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $admin = listAdmin($tenantA);
    $role = Role::findByName('tenant_admin', 'web');

    // 3 rows in A, 2 in B.
    Invitation::factory()->count(3)->create(['tenant_id' => $tenantA->id, 'role_id' => $role->id, 'invited_by_user_id' => $admin->id]);
    Invitation::factory()->count(2)->create(['tenant_id' => $tenantB->id, 'role_id' => $role->id, 'invited_by_user_id' => $admin->id]);

    $this->actingAs($admin);
    $response = $this->getJson('/api/v1/admin/users/invitations');

    $response->assertOk();
    expect($response->json('meta.total'))->toBe(3); // B's 2 excluded
});

it('filters by computed status — accepted only returns accepted rows', function (): void {
    $tenant = Tenant::factory()->create();
    $admin = listAdmin($tenant);
    $role = Role::findByName('tenant_admin', 'web');

    $accepter = User::factory()->forTenant($tenant)->create();

    // One pending, one accepted, one cancelled.
    Invitation::factory()->create(['tenant_id' => $tenant->id, 'role_id' => $role->id, 'invited_by_user_id' => $admin->id]);
    Invitation::factory()->accepted($accepter)->create(['tenant_id' => $tenant->id, 'role_id' => $role->id, 'invited_by_user_id' => $admin->id]);
    Invitation::factory()->cancelled($admin)->create(['tenant_id' => $tenant->id, 'role_id' => $role->id, 'invited_by_user_id' => $admin->id]);

    $this->actingAs($admin);

    $response = $this->getJson('/api/v1/admin/users/invitations?status=accepted');
    $response->assertOk();
    expect($response->json('meta.total'))->toBe(1);
    expect($response->json('data.0.status'))->toBe('accepted');

    // Pending → 1 row.
    $pendingResponse = $this->getJson('/api/v1/admin/users/invitations?status=pending');
    expect($pendingResponse->json('meta.total'))->toBe(1);
    expect($pendingResponse->json('data.0.status'))->toBe('pending');

    // Cancelled → 1 row.
    $cancelledResponse = $this->getJson('/api/v1/admin/users/invitations?status=cancelled');
    expect($cancelledResponse->json('meta.total'))->toBe(1);
    expect($cancelledResponse->json('data.0.status'))->toBe('cancelled');
});

it('422 when status filter is not a valid InvitationStatus value', function (): void {
    $tenant = Tenant::factory()->create();
    $admin = listAdmin($tenant);

    $this->actingAs($admin);
    $response = $this->getJson('/api/v1/admin/users/invitations?status=garbage');

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('status');
});
