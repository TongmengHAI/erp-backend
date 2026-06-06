<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────────
// RolesSchemaConstraintTest — Phase 2B Session 1.
//
// Pins the partial unique index behavior on the roles table. Exercises
// the DB layer DIRECTLY (DB::table()->insert) so the application layer
// (Eloquent unique-validation, FormRequest checks) doesn't catch the
// violation first. The triple-stack discipline (CLAUDE.md §10.4)
// requires the DB constraint to be the load-bearing backstop; this test
// is what catches the constraint silently dropping in a future
// migration.
//
// Two partial indexes replaced Spatie's base UNIQUE on
// (team_id, name, guard_name). The replacement is documented in the
// schema migration's class docblock. This test surface confirms BOTH
// partial indexes fire correctly AND that the soft-delete-aware reuse
// case (which the base UNIQUE BROKE) now works.
//
// Why exception messages are asserted: when a future bug attempts a
// duplicate insert and a developer reads the test failure, the
// constraint name (roles_custom_name_per_tenant_uniq or
// roles_system_name_uniq) tells them exactly which index fired. That's
// the grep-able anchor the slice-closer promotes as a discipline.
// ─────────────────────────────────────────────────────────────────────────────

use Database\Seeders\Framework\DefaultPermissionsSeeder;
use Database\Seeders\Framework\DefaultRolesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(DefaultPermissionsSeeder::class);
    $this->seed(DefaultRolesSeeder::class);
});

it('LOAD-BEARING: custom role name partial unique enforces per-tenant scope (two tenants OK, same tenant blocked)', function (): void {
    DB::table('roles')->insert([
        'team_id' => 1,
        'name' => 'Senior Accountant',
        'guard_name' => 'web',
        'is_system' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Different tenant + same name → OK.
    DB::table('roles')->insert([
        'team_id' => 2,
        'name' => 'Senior Accountant',
        'guard_name' => 'web',
        'is_system' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Same tenant + same name → blocked by roles_custom_name_per_tenant_uniq.
    expect(fn () => DB::table('roles')->insert([
        'team_id' => 1,
        'name' => 'Senior Accountant',
        'guard_name' => 'web',
        'is_system' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]))
        ->toThrow(QueryException::class, 'roles_custom_name_per_tenant_uniq');
});

it('LOAD-BEARING: soft-deleted custom role allows name reuse in same tenant', function (): void {
    // The case the base UNIQUE BROKE. Now allowed by the partial
    // index's WHERE deleted_at IS NULL clause.
    DB::table('roles')->insert([
        'team_id' => 1,
        'name' => 'Senior Accountant',
        'guard_name' => 'web',
        'is_system' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('roles')->where('name', 'Senior Accountant')->where('team_id', 1)->update([
        'deleted_at' => now(),
    ]);

    // Same name in same tenant, but the prior row is soft-deleted → OK.
    DB::table('roles')->insert([
        'team_id' => 1,
        'name' => 'Senior Accountant',
        'guard_name' => 'web',
        'is_system' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Two rows now exist with the same (team_id, name): one soft-deleted, one active.
    $rows = DB::table('roles')
        ->where('team_id', 1)
        ->where('name', 'Senior Accountant')
        ->get();
    expect($rows->count())->toBe(2);
    expect($rows->whereNull('deleted_at')->count())->toBe(1);
});

it('LOAD-BEARING: system role name uniqueness enforced by roles_system_name_uniq', function (): void {
    // Attempting to insert a second tenant_admin row via raw SQL must
    // fail by the partial system-role unique index — NOT by Spatie's
    // base UNIQUE (which has been dropped).
    expect(fn () => DB::table('roles')->insert([
        'team_id' => null,
        'name' => 'tenant_admin',
        'guard_name' => 'web',
        'is_system' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]))
        ->toThrow(QueryException::class, 'roles_system_name_uniq');
});

it('a custom role with the same name as a system role does NOT collide on either partial index', function (): void {
    // Edge case worth pinning: a custom role named "viewer" in a
    // tenant doesn't collide on roles_custom_name_per_tenant_uniq
    // (different team_id from system's NULL) nor on
    // roles_system_name_uniq (the custom row has is_system=false).
    // Application-layer FormRequest validation in Session 2 will
    // reject this case at 422; the DB layer alone does not.
    DB::table('roles')->insert([
        'team_id' => 1,
        'name' => 'viewer',
        'guard_name' => 'web',
        'is_system' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('roles')->where('name', 'viewer')->count())->toBe(2);
});
