<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 2B — Session 1: backfill is_system=true on the three default
 * role names.
 *
 * Separate from the schema migration because schema and data changes
 * are different concerns — if the schema migration succeeds but this
 * backfill fails (e.g. a role row was deleted between migrations on a
 * lightly-managed environment), the failure surface is clear: the
 * schema is in place, only the data step is incomplete.
 *
 * Idempotent: WHERE clause filters on name match; running twice
 * produces no change after the first run sets is_system=true. The
 * sibling EnsureSystemRolePermissionsMatchRegistry migration also
 * depends on is_system=true being set on these three rows; it runs
 * after this one (later timestamp).
 *
 * Why match by NAME and not by id: ids are environment-dependent
 * (seeders may run in different orders, ids may differ across
 * environments). The three names are the contract.
 *
 * Custom roles created before Phase 2B existed cannot — by definition
 * of Phase 2A's schema — exist; the table only carried the three
 * system rows. Future-proofing isn't needed.
 *
 * Forward-only per CLAUDE.md §3. down() resets is_system to false on
 * the three names for local dev convenience only.
 */
return new class extends Migration
{
    private const SYSTEM_ROLE_NAMES = ['tenant_admin', 'accountant', 'viewer'];

    public function up(): void
    {
        DB::table('roles')
            ->whereIn('name', self::SYSTEM_ROLE_NAMES)
            ->whereNull('team_id')
            ->update(['is_system' => true]);
    }

    public function down(): void
    {
        DB::table('roles')
            ->whereIn('name', self::SYSTEM_ROLE_NAMES)
            ->whereNull('team_id')
            ->update(['is_system' => false]);
    }
};
