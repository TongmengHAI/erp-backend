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
        Schema::create('departments', function (Blueprint $t): void {
            $t->id();

            $t->foreignId('tenant_id')
                ->constrained('tenants')
                ->restrictOnDelete();
            $t->foreignId('company_id')
                ->constrained('companies')
                ->restrictOnDelete();

            // code: human-friendly identifier, unique within (tenant, company).
            // Same shape as employees.employee_code — varchar(32) covers
            // typical org codes (D-OPS, FIN, ACME-SALES, etc.).
            $t->string('code', 32);

            $t->string('name', 255);

            // description bounded at 500 chars on purpose — this is a short
            // descriptor, not a notes blob. Frontend Zod schema enforces the
            // same cap so the UI doesn't accept characters the API will reject.
            $t->string('description', 500)->nullable();

            // Two-value enum mirroring CompanyStatus. No on_leave equivalent —
            // departments are either operational or not.
            $t->string('status', 16);

            $t->timestampsTz();
            $t->softDeletesTz();

            $t->unique(['tenant_id', 'company_id', 'code'], 'departments_tenant_company_code_unique');
            $t->index(['tenant_id', 'company_id', 'status'], 'departments_tenant_company_status_idx');
            // Supports ILIKE search against name in the canonical list query.
            $t->index(['tenant_id', 'company_id', 'name'], 'departments_tenant_company_name_idx');
        });

        // CHECK constraint on status — defense in depth. The DepartmentStatus
        // enum at the application layer is the primary guard; the DB CHECK
        // catches direct SQL inserts (seeders gone wrong, console fixes).
        DB::statement(
            "ALTER TABLE departments ADD CONSTRAINT departments_status_check
             CHECK (status IN ('active','archived'))"
        );
    }

    public function down(): void
    {
        // Forward-only in production (§7.E); down() exists for migrate:fresh symmetry only.
        Schema::dropIfExists('departments');
    }
};
