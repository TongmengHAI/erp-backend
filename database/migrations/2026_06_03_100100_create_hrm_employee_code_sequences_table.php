<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-company counter for auto-generated employee codes. State,
     * not config — kept on a separate table from hrm_settings so:
     *
     *   1. Updating the counter on every Employee create doesn't
     *      churn the Auditable trait on hrm_settings (the counter
     *      bump isn't a "settings change" event).
     *   2. Concurrency story is isolated — the SELECT FOR UPDATE
     *      row lock on this table doesn't block settings reads.
     *
     * Lazy initialization: this table starts empty. The
     * EmployeeCodeGenerator's `firstOrCreate` populates the row the
     * first time a company auto-generates a code. Companies that
     * never enable auto-gen never get a row. No backfill in the up().
     *
     * Concurrency invariant: two concurrent auto-gen creates MUST
     * produce distinct codes. Enforced by SELECT FOR UPDATE inside
     * DB::transaction in the generator. Same row lock is the gate;
     * the unique constraint on (tenant_id, company_id) below makes
     * a duplicate-insert path impossible.
     *
     * No Auditable, no SoftDeletes. Pure state table.
     */
    public function up(): void
    {
        Schema::create('hrm_employee_code_sequences', function (Blueprint $t): void {
            $t->id();

            $t->foreignId('tenant_id')
                ->constrained('tenants')
                ->restrictOnDelete();
            $t->foreignId('company_id')
                ->constrained('companies')
                ->restrictOnDelete();

            // Next value the generator will hand out + then increment.
            // Default 1 (Q5 decision: independent sequence starting at
            // 1, not parsing existing manual codes). Customizable later
            // via direct DB write if a company wants e.g. 1001 start;
            // not exposed in the v1 UI.
            $t->integer('next_value')->default(1);

            $t->timestampsTz();
        });

        DB::statement(
            'CREATE UNIQUE INDEX hrm_employee_code_sequences_unique_company
             ON hrm_employee_code_sequences (tenant_id, company_id)'
        );

        // CHECK: next_value must be positive. Defense against a manual
        // DB fix going negative; generator never decrements.
        DB::statement(
            'ALTER TABLE hrm_employee_code_sequences ADD CONSTRAINT hrm_employee_code_sequences_next_positive_check
             CHECK (next_value > 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('hrm_employee_code_sequences');
    }
};
