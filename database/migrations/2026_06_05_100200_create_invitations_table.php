<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2A — invitations table.
 *
 * One row per invitation attempt. Re-send creates a NEW row + soft-
 * deletes the old (per Q1 — preserves audit history of all invitation
 * attempts).
 *
 * Status is NOT a stored column. Per CLAUDE.md §10.3 (computed-state
 * default), the status accessor on the Invitation model resolves at
 * read-time from accepted_at / cancelled_at / expires_at. The
 * InvitationQueryService selects an SQL `CASE WHEN` for query-time
 * filtering. Default-computed prevents drift; the audit-trail
 * exception that justifies stored state (Accounting account_balances)
 * doesn't apply to invitations.
 *
 * Token is stored as a BCrypt hash via Hash::make. The raw token
 * (Str::random(43) — 256 bits) lives only in the URL sent to the
 * invitee and in the request body when they accept. Hash::check
 * verifies on accept (~200ms; one-time, non-hot-path).
 *
 * Partial unique index on (tenant_id, email) WHERE accepted_at IS
 * NULL AND cancelled_at IS NULL AND deleted_at IS NULL enforces Q11:
 * only one ACTIVE invitation per (tenant, email) at a time. Re-send
 * works because it soft-deletes the prior row before creating a new
 * one (transactional).
 *
 * users.email is GLOBALLY unique per the Phase 2A Option A resolution
 * — this table's (tenant_id, email) uniqueness is for the
 * "active invitation" gate, not for the eventual users row. The
 * Phase 2A invite-Action raises 422 email_globally_registered when
 * the email already maps to a users row in ANY tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('email', 254);
            $table->string('name', 255)->nullable();
            $table->foreignId('role_id')->constrained('roles')->restrictOnDelete();
            $table->string('token_hash', 255);
            $table->foreignId('invited_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('accepted_user_id')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by_user_id')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('expires_at', 'invitations_expires_at_idx');
            $table->index(['tenant_id', 'created_at'], 'invitations_tenant_created_idx');
        });

        // Partial unique index — only ONE active invitation per (tenant, email).
        // Re-send invalidates the old via soft-delete; cancel sets
        // cancelled_at. Both move the row out of the index's WHERE clause
        // so a fresh active row can be inserted.
        DB::statement(
            'CREATE UNIQUE INDEX invitations_active_per_tenant_email_uniq '
            .'ON invitations (tenant_id, email) '
            .'WHERE accepted_at IS NULL AND cancelled_at IS NULL AND deleted_at IS NULL'
        );

        // Composite consistency CHECK — same triple-stack discipline
        // as §10.4. If cancelled_at IS NOT NULL then cancelled_by_user_id
        // MUST be set; same for accepted_at ↔ accepted_user_id. Direct
        // SQL or careless seeders that violate this fail at the DB layer.
        DB::statement(
            'ALTER TABLE invitations ADD CONSTRAINT invitations_cancelled_consistency_check '
            .'CHECK ((cancelled_at IS NULL AND cancelled_by_user_id IS NULL) OR '
            .'(cancelled_at IS NOT NULL AND cancelled_by_user_id IS NOT NULL))'
        );
        DB::statement(
            'ALTER TABLE invitations ADD CONSTRAINT invitations_accepted_consistency_check '
            .'CHECK ((accepted_at IS NULL AND accepted_user_id IS NULL) OR '
            .'(accepted_at IS NOT NULL AND accepted_user_id IS NOT NULL))'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE invitations DROP CONSTRAINT IF EXISTS invitations_accepted_consistency_check');
        DB::statement('ALTER TABLE invitations DROP CONSTRAINT IF EXISTS invitations_cancelled_consistency_check');
        DB::statement('DROP INDEX IF EXISTS invitations_active_per_tenant_email_uniq');
        Schema::dropIfExists('invitations');
    }
};
