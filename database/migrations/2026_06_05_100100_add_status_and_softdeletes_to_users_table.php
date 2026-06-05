<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2A — Session 1: user lifecycle states.
 *
 * Adds two user-lifecycle dimensions used by the Phase 2A Disable / Enable /
 * Deactivate / Restore actions and by the LoginController's
 * authentication predicate:
 *
 *   1. status  — varchar(16) enum-like. Values:
 *        'active'   (default; can log in)
 *        'inactive' (soft-blocked; cannot log in; reversible via Enable)
 *
 *      Backed by the UserStatus PHP enum at app/Support/Identity/Enums/
 *      UserStatus.php. DB CHECK 'users_status_check' enforces the
 *      allowed values at the storage layer (triple-stack discipline per
 *      §10.4 — enum + FormRequest validation + DB CHECK).
 *
 *   2. deleted_at — SoftDeletes. Hard-removal semantic:
 *        NULL set     = "user removed from system" (Deactivate action)
 *        NULL cleared = "user restored" (Restore action)
 *
 *      Login is rejected when deleted_at IS NOT NULL OR status = 'inactive'.
 *      Both checks live in LoginController's predicate as independent named
 *      booleans ($statusOk and $notDeleted) per §10.17 split-not-relax.
 *
 * Composite index (tenant_id, status, deleted_at) supports the canonical
 * Phase 2A admin user-list query: "active users in this tenant, status
 * filter applied." Existing users.email index untouched.
 *
 * Backfill: every existing row gets status='active' via the column
 * default. deleted_at stays NULL. No data conversion required.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('status', 16)->default('active')->after('type');
            $table->softDeletes();
            $table->index(['tenant_id', 'status', 'deleted_at'], 'users_tenant_status_deleted_at_idx');
        });

        // DB CHECK — defense-in-depth against direct SQL / careless
        // seeders / future migration bugs that bypass the UserStatus enum
        // at the application layer. Constraint name follows the
        // 'users_super_admin_no_tenant_or_company_check' precedent.
        DB::statement(
            "ALTER TABLE users ADD CONSTRAINT users_status_check CHECK (status IN ('active', 'inactive'))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_status_check');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex('users_tenant_status_deleted_at_idx');
            $table->dropSoftDeletes();
            $table->dropColumn('status');
        });
    }
};
