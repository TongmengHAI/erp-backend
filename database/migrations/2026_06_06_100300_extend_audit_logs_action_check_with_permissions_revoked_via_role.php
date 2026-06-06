<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 2B — extend the audit_logs.action CHECK constraint to include
 * the new 'permissions_revoked_via_role' action.
 *
 * The Phase 2B UpdateRoleAction writes per-affected-user audit rows
 * with this action value when a custom role's permission set has
 * permissions removed. The new value augments the existing whitelist
 * — it doesn't replace any existing action — so future audit-row
 * queries that filter by action remain valid.
 *
 * Distinct from §10.4 (triple-stack validation): this isn't a UI/API/
 * DB invariant agreement; it's a one-time schema evolution where the
 * application layer started writing a new value that the DB layer
 * didn't yet allow. The right shape is "extend the allowlist via a
 * dedicated migration" so the change is reviewable and reversible.
 *
 * When a future module needs a new action value:
 *   1. Add a new migration in this naming pattern.
 *   2. List ALL current action values + the new one in the CHECK.
 *   3. Pin the new value in a test asserting the trait or Action
 *      emits it (per §10.24).
 *
 * down(): forward-only per CLAUDE.md §3 — restoring the old constraint
 * would forbid an action value that may have already been written to
 * audit rows. Best-effort reversal for local dev only.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE audit_logs DROP CONSTRAINT IF EXISTS audit_logs_action_check');
        DB::statement(
            'ALTER TABLE audit_logs ADD CONSTRAINT audit_logs_action_check '
            ."CHECK (action IN ('created','updated','soft_deleted','restored','hard_deleted','permissions_revoked_via_role'))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE audit_logs DROP CONSTRAINT IF EXISTS audit_logs_action_check');
        DB::statement(
            'ALTER TABLE audit_logs ADD CONSTRAINT audit_logs_action_check '
            ."CHECK (action IN ('created','updated','soft_deleted','restored','hard_deleted'))"
        );
    }
};
