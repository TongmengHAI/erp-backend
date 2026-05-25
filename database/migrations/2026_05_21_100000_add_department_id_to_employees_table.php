<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $t): void {
            // Nullable FK with ON DELETE SET NULL — see migration notes below.
            //
            // 1. Nullable: an employee can have no current department (e.g. an
            //    "on leave" hire whose former department was archived, or a
            //    company-wide role that doesn't fit one team). This is a
            //    legitimate permanent state, not a placeholder for "not yet
            //    set".
            //
            // 2. ON DELETE SET NULL (not RESTRICT): if a department is
            //    hard-deleted (rare — soft delete is the norm), employees
            //    should survive as "no current department" rather than block
            //    the delete. Soft-delete leaves the FK pointing at a tombstone
            //    row; belongsTo respects SoftDeletes and returns null for the
            //    relation, so the UI displays "—" gracefully.
            //
            // 3. The DB FK is unscoped (just references departments(id)). The
            //    same-tenant + same-company guarantee is enforced at the
            //    FormRequest layer via Rule::exists with a scoped where().
            //    Without that scoped condition a client could submit a
            //    foreign-tenant department_id and have it persist — that's
            //    the load-bearing isolation guard for this slice.
            $t->foreignId('department_id')
                ->nullable()
                ->after('job_title')
                ->constrained('departments')
                ->nullOnDelete();

            // Supports the new query path: "list employees in this department"
            // — both the ?department_id= API filter and the implicit join
            // when EmployeeResource eager-loads department on detail.
            $t->index(
                ['tenant_id', 'company_id', 'department_id'],
                'employees_tenant_company_department_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $t): void {
            $t->dropIndex('employees_tenant_company_department_idx');
            $t->dropConstrainedForeignId('department_id');
        });
    }
};
