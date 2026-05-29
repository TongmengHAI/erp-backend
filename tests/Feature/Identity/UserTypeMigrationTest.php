<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────────
// UserTypeMigrationTest — exercises the three CHECK constraints from
// 2026_06_04_100000_add_type_to_users_table.php directly via raw DB writes.
//
// Per §10.4 (Triple-stack validation discipline): the DB CHECK layer is the
// final backstop, and it must be tested against direct DB::table()->insert()
// — going through the ORM / FormRequest would let a higher layer catch the
// violation first and silently mask a broken constraint.
//
// Each test asserts that the QueryException's message NAMES the specific
// constraint that fired, so a future developer debugging a violation knows
// which rule rejected the row (per Session 1 plan tightening).
// ─────────────────────────────────────────────────────────────────────────────

use App\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('backfills existing users to type=tenant_user (default)', function (): void {
    $tenant = Tenant::factory()->create();

    $id = DB::table('users')->insertGetId([
        'name' => 'Backfilled User',
        'email' => 'backfilled@example.test',
        'password' => Hash::make('password'),
        'tenant_id' => $tenant->id,
        'created_at' => now(),
        'updated_at' => now(),
        // Deliberately NOT setting `type` — relies on the column default
        // 'tenant_user'. Confirms the default is honoured at the DB level.
    ]);

    $row = DB::table('users')->where('id', $id)->first();
    expect($row->type)->toBe('tenant_user');
});

it('LOAD-BEARING: users_type_enum_check rejects an unknown type value', function (): void {
    $tenant = Tenant::factory()->create();

    $thrown = false;
    try {
        DB::table('users')->insert([
            'name' => 'Bad Type',
            'email' => 'badtype@example.test',
            'password' => Hash::make('password'),
            'tenant_id' => $tenant->id,
            'type' => 'gibberish',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    } catch (QueryException $e) {
        $thrown = true;
        // Constraint name must appear in the message so future debuggers
        // can grep the migrations folder for the failing rule.
        expect($e->getMessage())->toContain('users_type_enum_check');
    }

    expect($thrown)->toBeTrue('Expected QueryException naming users_type_enum_check.');
});

it('LOAD-BEARING: users_super_admin_no_tenant_or_company_check rejects a super_admin with non-null tenant_id', function (): void {
    $tenant = Tenant::factory()->create();

    $thrown = false;
    try {
        DB::table('users')->insert([
            'name' => 'Bad SA',
            'email' => 'badsa@example.test',
            'password' => Hash::make('password'),
            'tenant_id' => $tenant->id, // ← violates: SA must have tenant_id NULL
            'type' => 'super_admin',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    } catch (QueryException $e) {
        $thrown = true;
        expect($e->getMessage())->toContain('users_super_admin_no_tenant_or_company_check');
    }

    expect($thrown)->toBeTrue('Expected QueryException naming users_super_admin_no_tenant_or_company_check.');
});

it('LOAD-BEARING: users_super_admin_no_tenant_or_company_check rejects a super_admin with non-null current_company_id', function (): void {
    // Covers a different limb of the same composite CHECK — the rule
    // requires ALL FOUR FK columns to be NULL for SA. This test pins the
    // current_company_id limb; the previous test pins tenant_id. Together
    // they prove the AND-joined branches all fire as expected.
    $thrown = false;
    try {
        DB::table('users')->insert([
            'name' => 'SA with Company',
            'email' => 'sawithcompany@example.test',
            'password' => Hash::make('password'),
            'tenant_id' => null,
            'current_tenant_id' => null,
            'default_company_id' => null,
            'current_company_id' => 999, // ← violates: SA must have ALL FOUR null
            'type' => 'super_admin',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    } catch (QueryException $e) {
        $thrown = true;
        expect($e->getMessage())->toContain('users_super_admin_no_tenant_or_company_check');
    }

    expect($thrown)->toBeTrue('Expected QueryException naming users_super_admin_no_tenant_or_company_check.');
});

it('LOAD-BEARING: users_tenant_user_has_tenant_check rejects a tenant_user with null tenant_id', function (): void {
    $thrown = false;
    try {
        DB::table('users')->insert([
            'name' => 'Orphan Tenant User',
            'email' => 'orphan@example.test',
            'password' => Hash::make('password'),
            'tenant_id' => null, // ← violates: tenant_user must have tenant_id
            'type' => 'tenant_user',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    } catch (QueryException $e) {
        $thrown = true;
        expect($e->getMessage())->toContain('users_tenant_user_has_tenant_check');
    }

    expect($thrown)->toBeTrue('Expected QueryException naming users_tenant_user_has_tenant_check.');
});

it('accepts a valid super_admin row with all four FK columns NULL', function (): void {
    // Positive case — proves the composite CHECK isn't over-restrictive.
    // The matching row shape is what SuperAdminSeeder + the Artisan
    // command produce.
    $id = DB::table('users')->insertGetId([
        'name' => 'Valid SA',
        'email' => 'validsa@example.test',
        'password' => Hash::make('password'),
        'tenant_id' => null,
        'current_tenant_id' => null,
        'default_company_id' => null,
        'current_company_id' => null,
        'type' => 'super_admin',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $row = DB::table('users')->where('id', $id)->first();
    expect($row->type)->toBe('super_admin');
    expect($row->tenant_id)->toBeNull();
});
