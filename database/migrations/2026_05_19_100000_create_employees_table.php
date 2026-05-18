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
        Schema::create('employees', function (Blueprint $t): void {
            $t->id();

            $t->foreignId('tenant_id')
                ->constrained('tenants')
                ->restrictOnDelete();
            $t->foreignId('company_id')
                ->constrained('companies')
                ->restrictOnDelete();

            // employee_code: human-friendly identifier, unique within (tenant, company).
            // varchar(32) covers typical HR codes (E-1234, ACME-001, etc.).
            $t->string('employee_code', 32);

            $t->string('full_name', 255);

            // Email is nullable — not every employee in scope (e.g. shop floor,
            // contractors) has a corporate inbox. Not unique either: shared
            // family addresses are common in the demo market.
            $t->string('email', 255)->nullable();

            // job_title is plain text in this slice — no Position FK. The
            // Positions table is deliberately out of scope (graded-assignment
            // cut). A future slice may swap this for a nullable position_id.
            $t->string('job_title', 255)->nullable();

            $t->date('hire_date');

            $t->string('status', 16);

            $t->timestampsTz();
            $t->softDeletesTz();

            $t->unique(['tenant_id', 'company_id', 'employee_code'], 'employees_tenant_company_code_unique');
            $t->index(['tenant_id', 'company_id', 'status'], 'employees_tenant_company_status_idx');
            // Supports the canonical "list by name within current company" query.
            $t->index(['tenant_id', 'company_id', 'full_name'], 'employees_tenant_company_name_idx');
        });

        // CHECK constraint on status — defense in depth. The EmployeeStatus
        // enum at the application layer is the primary guard; the DB CHECK
        // catches direct SQL inserts (seeders gone wrong, console fixes).
        DB::statement(
            "ALTER TABLE employees ADD CONSTRAINT employees_status_check
             CHECK (status IN ('active','on_leave','terminated'))"
        );
    }

    public function down(): void
    {
        // Forward-only in production (§7.E); down() exists for migrate:fresh symmetry only.
        Schema::dropIfExists('employees');
    }
};
