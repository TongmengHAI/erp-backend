<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add a nullable position_id FK to employees. Mirrors the
     * department_id FK shape exactly (see
     * 2026_05_21_100000_add_department_id_to_employees_table.php).
     *
     *   1. Nullable: an employee can have no current position (e.g. a
     *      new hire before role assignment, or a company-wide person
     *      whose function doesn't fit a single Position).
     *
     *   2. ON DELETE SET NULL: if a position is hard-deleted, employees
     *      survive as "no current position". Soft-delete leaves the FK
     *      pointing at a tombstone row; belongsTo respects SoftDeletes
     *      and returns null for the relation.
     *
     *   3. The DB FK is unscoped. The same-tenant + same-company
     *      guarantee is enforced at the FormRequest layer via
     *      Rule::exists with a scoped where() — same load-bearing
     *      pattern as department_id.
     *
     * THIS MIGRATION IS PURELY ADDITIVE. The destructive cutover
     * (dropping job_title) is in the SEPARATE migration
     * 2026_05_30_100200_drop_job_title_from_employees_table.php.
     *
     * Production deployment sequence (see hrm.md):
     *   1. Run THIS migration (add position_id, leaves all rows with
     *      position_id = NULL)
     *   2. Run the per-tenant data-migration command (NOT in this
     *      slice — deployment runbook responsibility)
     *   3. Only THEN run the drop_job_title migration
     *
     * Running #1 + #3 consecutively without #2 in production would
     * lose all job_title values irrecoverably.
     */
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $t): void {
            $t->foreignId('position_id')
                ->nullable()
                ->after('department_id')
                ->constrained('positions')
                ->nullOnDelete();

            // Supports the "?position_id=" filter on the Employee index
            // and the implicit join when EmployeeResource eager-loads
            // position on detail. Mirrors employees_tenant_company_department_idx.
            $t->index(
                ['tenant_id', 'company_id', 'position_id'],
                'employees_tenant_company_position_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $t): void {
            $t->dropIndex('employees_tenant_company_position_idx');
            $t->dropConstrainedForeignId('position_id');
        });
    }
};
