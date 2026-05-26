<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('positions', function (Blueprint $t): void {
            $t->id();

            $t->foreignId('tenant_id')
                ->constrained('tenants')
                ->restrictOnDelete();
            $t->foreignId('company_id')
                ->constrained('companies')
                ->restrictOnDelete();

            // code: human-friendly identifier, unique within (tenant, company).
            // Same shape as employees.employee_code and departments.code —
            // varchar(32) covers typical role codes (P-OPS-MGR, P-FIN-SR,
            // SALES-LEAD, etc.).
            $t->string('code', 32);

            // title is the user-facing role label ("Operations Manager",
            // "Senior Accountant"). Distinguished from departments.name —
            // a Department names a TEAM; a Position names a ROLE.
            $t->string('title', 255);

            // description bounded at 500 chars on purpose — short
            // descriptor of responsibilities, not a job posting.
            $t->string('description', 500)->nullable();

            // Two-value enum mirroring DepartmentStatus.
            $t->string('status', 16);

            $t->timestampsTz();
            $t->softDeletesTz();

            $t->index(['tenant_id', 'company_id', 'status'], 'positions_tenant_company_status_idx');
            // Supports ILIKE search against title in the canonical list query.
            $t->index(['tenant_id', 'company_id', 'title'], 'positions_tenant_company_title_idx');
        });

        // Partial unique index on code — WHERE deleted_at IS NULL so
        // soft-deleted positions don't block re-creating with the same
        // code. Same pattern as attendance_records' partial unique index.
        // (Note: departments uses a non-partial unique index — a known
        // limitation flagged in the Attendance slice; Positions adopts the
        // newer partial-index discipline from the start.)
        DB::statement(
            'CREATE UNIQUE INDEX positions_tenant_company_code_unique
             ON positions (tenant_id, company_id, code)
             WHERE deleted_at IS NULL'
        );

        // CHECK constraint on status — defense in depth against direct SQL.
        DB::statement(
            "ALTER TABLE positions ADD CONSTRAINT positions_status_check
             CHECK (status IN ('active','archived'))"
        );
    }

    public function down(): void
    {
        // Forward-only in production (§7.E); down() exists for migrate:fresh symmetry only.
        Schema::dropIfExists('positions');
    }
};
