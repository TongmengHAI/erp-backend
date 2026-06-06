<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2B — Session 1: roles schema for custom-role editor.
 *
 * Three additive concerns plus one constraint replacement:
 *
 *   1. is_system  — boolean, NOT NULL, default false. System rows
 *      (tenant_admin, accountant, viewer) get true via the sibling
 *      backfill migration. Custom rows always false. Drives UI
 *      affordances (edit/delete hidden on system) and API authorization
 *      (mutation endpoints reject is_system=true rows with 403).
 *
 *   2. description — text, nullable. Admin-entered. Surfaces only on
 *      custom roles via the form editor; system role descriptions
 *      live in resources/lang/en/roles.php (i18n keys, not row data).
 *
 *   3. deleted_at — SoftDeletes. Custom roles are soft-deletable; the
 *      role_user join rows are preserved, so restoring the role
 *      restores effective permissions to the assigned users. System
 *      roles are never soft-deleted (the API rejects DELETE on them).
 *
 *   4. UNIQUE constraint REPLACEMENT — not augmentation. Spatie's
 *      base UNIQUE on (team_id, name, guard_name) is dropped because
 *      it BREAKS soft-delete-aware name reuse: a tenant who soft-
 *      deletes "Senior Accountant" cannot then create a new role with
 *      the same name (the constraint fires against the still-present
 *      soft-deleted row). Two partial unique indexes replace it,
 *      one per uniqueness domain:
 *
 *        roles_custom_name_per_tenant_uniq
 *          (team_id, name) WHERE is_system = false AND deleted_at IS NULL
 *          — primary uniqueness for custom roles. Two tenants OK with
 *            same name; same tenant blocked; soft-deleted excluded so
 *            name reuse is allowed.
 *
 *        roles_system_name_uniq
 *          (name, guard_name) WHERE is_system = true
 *          — primary uniqueness for system rows. The three system
 *            roles all have team_id = NULL (Postgres treats NULLs as
 *            distinct in unique indexes, so the base UNIQUE didn't
 *            actually enforce this either — the partial filter on
 *            is_system = true is what's load-bearing).
 *
 *      These are NAMED indexes (CREATE UNIQUE INDEX <name>) so a
 *      future bug attempting a duplicate insert produces a
 *      QueryException naming the specific constraint — grep-able in
 *      test failures and production logs.
 *
 * Phase 2B slice-closer §10 candidate: this is the first instance of
 * "When soft-delete semantics arrive, base UNIQUE constraints that
 * don't filter on deleted_at IS NULL BREAK reuse-after-deletion and
 * should be REPLACED with partial unique indexes — not augmented by
 * them." Distinct shape from §10.4 (triple-stack); promoted at slice
 * closer.
 *
 * Forward-only per CLAUDE.md §3 — down() is a best-effort reversal for
 * local dev only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            // Drop Spatie's base UNIQUE — replaced by the two partial
            // indexes below. Column-array form lets Laravel resolve the
            // auto-generated index name (roles_team_id_name_guard_name_unique).
            $table->dropUnique(['team_id', 'name', 'guard_name']);

            $table->boolean('is_system')->default(false)->after('name');
            $table->text('description')->nullable()->after('is_system');
            $table->softDeletes();
        });

        // Partial unique index — custom roles. The PRIMARY uniqueness
        // mechanism for is_system=false rows post-replacement. Same
        // shape as Phase 2A's invitations_active_per_tenant_email_uniq
        // partial index (soft-delete-aware uniqueness gate).
        DB::statement(
            'CREATE UNIQUE INDEX roles_custom_name_per_tenant_uniq '
            .'ON roles (team_id, name) '
            .'WHERE is_system = false AND deleted_at IS NULL'
        );

        // Partial unique index — system roles. Filters on is_system=true
        // so a future SQL bug that flips is_system on a custom row
        // doesn't accidentally collide with the system namespace at
        // insert-time; instead the collision surfaces at the flip-time
        // raw UPDATE, where it's easier to diagnose.
        DB::statement(
            'CREATE UNIQUE INDEX roles_system_name_uniq '
            .'ON roles (name, guard_name) '
            .'WHERE is_system = true'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS roles_system_name_uniq');
        DB::statement('DROP INDEX IF EXISTS roles_custom_name_per_tenant_uniq');

        Schema::table('roles', function (Blueprint $table): void {
            $table->dropSoftDeletes();
            $table->dropColumn('description');
            $table->dropColumn('is_system');
            $table->unique(['team_id', 'name', 'guard_name']);
        });
    }
};
