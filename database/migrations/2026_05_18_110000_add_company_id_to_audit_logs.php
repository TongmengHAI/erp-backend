<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // ─── 1. Add company_id to the parent partitioned table ─────────────
        // PG 13+ declarative partitioning propagates ALTER TABLE ADD COLUMN
        // to every existing AND future partition automatically. The original
        // table is PARTITION BY RANGE (created_at), so this single statement
        // updates all 14 monthly partitions in one shot.
        //
        // Nullable on purpose. NULL means one of:
        //   (a) the audited model is tenant-only (no company dimension at all —
        //       e.g. Tenant, Company itself, User identity rows);
        //   (b) the audited model IS company-scoped but the row was written
        //       outside a company context (rare — system seeders, cross-company
        //       admin operations). Same shape as the tenant_id nullable rule.
        //
        // FK with ON DELETE RESTRICT mirrors tenant_id: audit rows pin the
        // company they reference, so the company can't be hard-deleted while
        // audit history exists. Soft delete via status=archived is the
        // intended retirement path.
        DB::statement('ALTER TABLE audit_logs ADD COLUMN company_id BIGINT NULL REFERENCES companies(id) ON DELETE RESTRICT');

        DB::statement("COMMENT ON COLUMN audit_logs.company_id IS 'NULL means either the audited model is not company-scoped (tenant-only models like Tenant, Company, User) or the row was written outside a company context. Same nullable semantic as tenant_id — no magic IDs.'");

        // ─── 2. Composite read-pattern index ────────────────────────────────
        // Per-company audit drill-down for a specific entity:
        //   WHERE tenant_id = ? AND company_id = ? AND auditable_type = ?
        //         AND auditable_id = ?  ORDER BY created_at DESC
        // The existing audit_logs_auditable_idx covers (auditable_type,
        // auditable_id, created_at DESC) but does NOT prefix by tenant/company,
        // so cross-tenant or cross-company filtering would still need a
        // secondary lookup. The new index supports the canonical "show me
        // this entity's history within this company" query directly.
        //
        // Created on the parent — PG propagates the index definition to all
        // existing and future partitions (same mechanism the original migration
        // relies on for the other four indexes).
        DB::statement('CREATE INDEX audit_logs_tenant_company_auditable_idx ON audit_logs (tenant_id, company_id, auditable_type, auditable_id)');
    }

    public function down(): void
    {
        // Forward-only in production (§7.E); down() exists for migrate:fresh symmetry.
        DB::statement('DROP INDEX IF EXISTS audit_logs_tenant_company_auditable_idx');
        DB::statement('ALTER TABLE audit_logs DROP COLUMN IF EXISTS company_id');
    }
};
