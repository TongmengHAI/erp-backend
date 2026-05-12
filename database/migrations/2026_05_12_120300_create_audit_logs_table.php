<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // ─── 1. Parent partitioned table ────────────────────────────────────
        // Postgres native RANGE partitioning on created_at. The partition key
        // MUST be part of every unique/primary index, so PK is (id, created_at).
        // No updated_at column — audit rows are append-only by design (§G).
        DB::statement(<<<'SQL'
            CREATE TABLE audit_logs (
                id              BIGSERIAL,
                tenant_id       BIGINT      NULL REFERENCES tenants(id) ON DELETE RESTRICT,

                auditable_type  VARCHAR(255) NOT NULL,
                auditable_id    BIGINT       NOT NULL,
                action          VARCHAR(32)  NOT NULL
                    CHECK (action IN ('created','updated','soft_deleted','restored','hard_deleted')),

                actor_type      VARCHAR(255) NULL,
                actor_id        BIGINT       NULL,

                before          JSONB        NULL,
                after           JSONB        NULL,

                ip              INET         NULL,
                user_agent      VARCHAR(500) NULL,
                request_id      UUID         NULL,

                created_at      TIMESTAMPTZ  NOT NULL,

                PRIMARY KEY (id, created_at)
            ) PARTITION BY RANGE (created_at)
        SQL);

        // ─── 2. Column comments — JSONB shape (§E) + tenant_id semantic ─────
        DB::statement("COMMENT ON COLUMN audit_logs.tenant_id IS 'NULL means the audit event is not tenant-scoped (e.g. the Tenant model itself being created, super-admin cross-tenant actions, system seeders). No magic IDs.'");
        DB::statement("COMMENT ON COLUMN audit_logs.before IS 'Diff-only: contains only the keys whose values changed (for action=updated/soft_deleted/restored) or the full filtered attribute set (for action=hard_deleted). NULL for action=created.'");
        DB::statement("COMMENT ON COLUMN audit_logs.after IS 'Diff-only: contains only the keys whose values changed (for action=updated/soft_deleted/restored) or the full filtered attribute set (for action=created). NULL for action=hard_deleted.'");

        // ─── 3. Indexes on the parent (auto-propagated to partitions) ──────
        // Read patterns: tenant audit trail, per-entity history, per-actor activity.
        DB::statement('CREATE INDEX audit_logs_tenant_id_created_at_idx ON audit_logs (tenant_id, created_at DESC)');
        DB::statement('CREATE INDEX audit_logs_auditable_idx ON audit_logs (auditable_type, auditable_id, created_at DESC)');
        DB::statement('CREATE INDEX audit_logs_actor_idx ON audit_logs (actor_id, created_at DESC)');
        DB::statement('CREATE INDEX audit_logs_action_idx ON audit_logs (action, created_at DESC)');

        // ─── 4. Immutability trigger — append-only at the DB level (§G) ────
        // PG 13+ propagates parent-table triggers to all current AND future
        // partitions automatically. Application bugs cannot corrupt the audit
        // trail because the DB itself refuses UPDATE/DELETE.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_logs_block_modification()
            RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'audit_logs is append-only; UPDATE and DELETE are blocked by design.';
            END;
            $$ LANGUAGE plpgsql
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER audit_logs_block_update_or_delete
            BEFORE UPDATE OR DELETE ON audit_logs
            FOR EACH ROW EXECUTE FUNCTION audit_logs_block_modification()
        SQL);

        // ─── 5. Initial monthly partitions: previous month + current + 12 ahead
        // The audit:partitions:rollover scheduled command extends the window.
        $start = now()->subMonthNoOverflow()->startOfMonth();
        for ($i = 0; $i < 14; $i++) {
            $monthStart = $start->copy()->addMonthsNoOverflow($i)->startOfMonth();
            $monthEnd = $monthStart->copy()->addMonthNoOverflow()->startOfMonth();
            $name = 'audit_logs_'.$monthStart->format('Y_m');

            DB::statement(sprintf(
                "CREATE TABLE %s PARTITION OF audit_logs FOR VALUES FROM ('%s') TO ('%s')",
                $name,
                $monthStart->toDateString(),
                $monthEnd->toDateString(),
            ));
        }
    }

    public function down(): void
    {
        // Forward-only in production (§E); down() exists for local migrate:fresh symmetry only.
        DB::statement('DROP TRIGGER IF EXISTS audit_logs_block_update_or_delete ON audit_logs');
        DB::statement('DROP FUNCTION IF EXISTS audit_logs_block_modification()');
        DB::statement('DROP TABLE IF EXISTS audit_logs CASCADE'); // cascades to all partitions
    }
};
