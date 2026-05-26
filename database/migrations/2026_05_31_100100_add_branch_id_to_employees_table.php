<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add a nullable branch_id FK to employees. Mirrors position_id
     * and department_id verbatim — same nullable + ON DELETE SET NULL
     * shape, same scoped-exists guarantee enforced at the FormRequest
     * layer (not the DB).
     *
     * Purely additive — no cutover work (unlike the Positions slice
     * which dropped job_title). Existing employees land with
     * branch_id = NULL on day one; assignments happen via the
     * Employee edit form.
     */
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $t): void {
            $t->foreignId('branch_id')
                ->nullable()
                ->after('position_id')
                ->constrained('branches')
                ->nullOnDelete();

            $t->index(
                ['tenant_id', 'company_id', 'branch_id'],
                'employees_tenant_company_branch_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $t): void {
            $t->dropIndex('employees_tenant_company_branch_idx');
            $t->dropConstrainedForeignId('branch_id');
        });
    }
};
