<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────────
// RoleAuditableTest — Phase 2B Session 1.
//
// Pins the trait-emergent audit action values on the extended Role
// model per CLAUDE.md §10.24. The Role model composes
// Auditable + SoftDeletes — same shape as User. The trait emits
// action='soft_deleted' (NOT 'deleted') on $role->delete() because of
// the SoftDeletes branch in writeAuditOnDeleted. Pinning this at the
// trait-using layer protects every downstream Action layer (Session 2
// DeleteRoleAction's audit assertions) from a regression.
//
// This is the CANONICAL pre-emptive instance of §10.24: the audit
// action value is pinned BEFORE the downstream Action is written, so
// when Session 2's DeleteRoleAction-layer test asserts the same
// 'soft_deleted' value, both fail loud if Auditable's behavior ever
// drifts.
//
// User authenticated for the actor_id capture — see Auditable docblock.
// ─────────────────────────────────────────────────────────────────────────────

use App\Domain\Identity\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Audit\Models\AuditLog;
use Database\Seeders\Framework\DefaultPermissionsSeeder;
use Database\Seeders\Framework\DefaultRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(DefaultPermissionsSeeder::class);
    $this->seed(DefaultRolesSeeder::class);
});

it('creating a custom role writes an audit row with action=created', function (): void {
    $tenant = Tenant::factory()->create();
    $actor = User::factory()->forTenant($tenant)->create();
    $this->actingAs($actor);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);

    $role = Role::create([
        'name' => 'Senior Accountant',
        'guard_name' => 'web',
        'team_id' => $tenant->id,
        'is_system' => false,
        'description' => 'Senior accounting role',
    ]);

    $row = AuditLog::query()
        ->where('auditable_type', Role::class)
        ->where('auditable_id', $role->id)
        ->where('action', 'created')
        ->latest('id')
        ->first();

    expect($row)->not->toBeNull();
    expect($row->actor_id)->toBe($actor->id);
    expect($row->after)->toHaveKey('name');
    expect($row->after['name'])->toBe('Senior Accountant');
});

it('updating a custom role writes an audit row with action=updated and before/after diff', function (): void {
    $tenant = Tenant::factory()->create();
    $actor = User::factory()->forTenant($tenant)->create();
    $this->actingAs($actor);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);

    $role = Role::create([
        'name' => 'Junior Role',
        'guard_name' => 'web',
        'team_id' => $tenant->id,
        'is_system' => false,
        'description' => 'Original',
    ]);

    $role->update(['description' => 'Updated']);

    $row = AuditLog::query()
        ->where('auditable_type', Role::class)
        ->where('auditable_id', $role->id)
        ->where('action', 'updated')
        ->latest('id')
        ->first();

    expect($row)->not->toBeNull();
    expect($row->before)->toEqual(['description' => 'Original']);
    expect($row->after)->toEqual(['description' => 'Updated']);
});

it("LOAD-BEARING: soft-deleting a custom role writes an audit row with action='soft_deleted'", function (): void {
    // Per §10.24, the value emitted by Auditable's writeAuditOnDeleted
    // handler for SoftDeletes models is 'soft_deleted' — NOT 'deleted'.
    // This test pins that exact string at the trait-using layer. Any
    // future Auditable refactor that changes the emitted action string
    // fails this test AND any downstream Action-layer test (Session 2
    // DeleteRoleAction). Both fire loud, source layer first.
    $tenant = Tenant::factory()->create();
    $actor = User::factory()->forTenant($tenant)->create();
    $this->actingAs($actor);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);

    $role = Role::create([
        'name' => 'Soon To Be Deleted',
        'guard_name' => 'web',
        'team_id' => $tenant->id,
        'is_system' => false,
    ]);

    $role->delete();

    $row = AuditLog::query()
        ->where('auditable_type', Role::class)
        ->where('auditable_id', $role->id)
        ->where('action', 'soft_deleted')
        ->latest('id')
        ->first();

    expect($row)->not->toBeNull();
    expect($row->actor_id)->toBe($actor->id);
    // Defense-in-depth assertion: the exact action string must match.
    // A regression that emitted 'deleted' (the natural Eloquent event
    // name) instead of 'soft_deleted' (the Auditable-derived value)
    // would be silently caught by the where() clause above failing
    // — this extra assertion makes the intent explicit.
    expect($row->action)->toBe('soft_deleted');
});

it("restoring a soft-deleted custom role writes an audit row with action='restored'", function (): void {
    $tenant = Tenant::factory()->create();
    $actor = User::factory()->forTenant($tenant)->create();
    $this->actingAs($actor);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);

    $role = Role::create([
        'name' => 'Restorable Role',
        'guard_name' => 'web',
        'team_id' => $tenant->id,
        'is_system' => false,
    ]);
    $role->delete();
    $role->restore();

    $row = AuditLog::query()
        ->where('auditable_type', Role::class)
        ->where('auditable_id', $role->id)
        ->where('action', 'restored')
        ->latest('id')
        ->first();

    expect($row)->not->toBeNull();
    expect($row->action)->toBe('restored');
});
