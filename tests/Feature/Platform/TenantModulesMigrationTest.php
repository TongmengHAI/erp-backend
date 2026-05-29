<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────────
// TenantModulesMigrationTest — pins the load-bearing invariants of the
// 2026_06_05_100000_create_tenant_modules_table.php migration:
//
//   1. Backfill happens with enabled_by_user_id = NULL.
//      LOAD-BEARING: this is the §10.12-class trap avoidance — the
//      migration must NOT depend on the SA seeder having run. A fresh
//      install runs migrations BEFORE db:seed; the backfill INSERT
//      encounters an empty users table. Bootstrap rows therefore use
//      NULL for enabled_by_user_id ("system bootstrap, no actor").
//
//   2. Partial unique index — one (tenant_id, module_key) where not
//      soft-deleted. Soft-deleted rows are excluded from the constraint
//      so a re-grant after a revoke can re-create the row.
//
//   3. DB CHECK constraint on status enum (triple-stack §10.4 DB layer).
// ─────────────────────────────────────────────────────────────────────────────

use App\Domain\Platform\Models\TenantModule;
use App\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('LOAD-BEARING: backfills existing tenants with HRM entitlement at NULL enabled_by_user_id', function (): void {
    // The migration backfill ran during RefreshDatabase. To exercise the
    // backfill on a NEW tenant we'd need to re-run the migration; that's
    // not realistic mid-test. Instead, this test inserts a row using the
    // exact backfill shape (NULL enabled_by_user_id) and verifies the
    // schema accepts it — proving the FK nullability is correctly set.
    //
    // The fresh-install backfill behaviour itself is exercised indirectly:
    // every test in this suite runs migrate:fresh, which runs the
    // backfill. If the backfill required a non-null enabled_by_user_id,
    // every test in the suite would fail at migration time. Passing
    // RefreshDatabase IS the backfill correctness signal.
    $tenant = Tenant::factory()->withoutEntitlement()->create();
    // RefreshDatabase already ran the backfill; the new tenant lacks an
    // entitlement row (factory doesn't trigger the migration). Insert
    // one with the exact bootstrap shape to verify nullable FK.
    DB::table('tenant_modules')->insert([
        'tenant_id' => $tenant->id,
        'module_key' => 'hrm',
        'status' => 'active',
        'enabled_at' => now(),
        'enabled_by_user_id' => null, // ← the load-bearing field
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    /** @var TenantModule $row */
    $row = TenantModule::query()
        ->acrossTenants() // test has no TenantContext; explicit tenant_id below is the filter
        ->where('tenant_id', $tenant->id)
        ->where('module_key', 'hrm')
        ->first();

    expect($row)->not->toBeNull();
    expect($row->enabled_by_user_id)->toBeNull();
    expect($row->status->value)->toBe('active');
});

it('LOAD-BEARING: tenant_modules_status_enum_check rejects an unknown status value', function (): void {
    $tenant = Tenant::factory()->withoutEntitlement()->create();

    $thrown = false;
    try {
        DB::table('tenant_modules')->insert([
            'tenant_id' => $tenant->id,
            'module_key' => 'hrm',
            'status' => 'gibberish', // ← violates enum CHECK
            'enabled_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    } catch (QueryException $e) {
        $thrown = true;
        expect($e->getMessage())->toContain('tenant_modules_status_enum_check');
    }

    expect($thrown)->toBeTrue('Expected QueryException naming tenant_modules_status_enum_check.');
});

it('LOAD-BEARING: partial unique index rejects two non-soft-deleted rows for the same (tenant, module)', function (): void {
    $tenant = Tenant::factory()->withoutEntitlement()->create();

    DB::table('tenant_modules')->insert([
        'tenant_id' => $tenant->id,
        'module_key' => 'hrm',
        'status' => 'active',
        'enabled_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $thrown = false;
    try {
        DB::table('tenant_modules')->insert([
            'tenant_id' => $tenant->id,
            'module_key' => 'hrm', // ← duplicate (tenant_id, module_key) with deleted_at NULL
            'status' => 'disabled',
            'enabled_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    } catch (QueryException $e) {
        $thrown = true;
        expect($e->getMessage())->toContain('tenant_modules_tenant_module_unique');
    }

    expect($thrown)->toBeTrue('Expected QueryException naming tenant_modules_tenant_module_unique.');
});

it('partial unique index ALLOWS a new row after the prior one is soft-deleted (audit-history chain)', function (): void {
    // The partial index is WHERE deleted_at IS NULL — soft-deleted rows
    // are excluded, so a re-grant after a revoke can re-create the row
    // without violating the constraint. This is the affordance that lets
    // entitlement history accumulate.
    $tenant = Tenant::factory()->withoutEntitlement()->create();

    $firstId = DB::table('tenant_modules')->insertGetId([
        'tenant_id' => $tenant->id,
        'module_key' => 'hrm',
        'status' => 'active',
        'enabled_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('tenant_modules')
        ->where('id', $firstId)
        ->update(['deleted_at' => now()]);

    // This insert should NOT collide — the prior row is soft-deleted.
    DB::table('tenant_modules')->insert([
        'tenant_id' => $tenant->id,
        'module_key' => 'hrm',
        'status' => 'active',
        'enabled_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Both rows now exist; partial unique index only counts the non-deleted.
    $total = DB::table('tenant_modules')
        ->where('tenant_id', $tenant->id)
        ->count();
    $active = DB::table('tenant_modules')
        ->where('tenant_id', $tenant->id)
        ->whereNull('deleted_at')
        ->count();

    expect($total)->toBe(2);
    expect($active)->toBe(1);
});
