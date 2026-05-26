<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Leave balance per employee per type per year.
     *
     *   allocated_days  — what the company granted the employee for the period.
     *   remaining_days  — NOT a stored column. Computed at query time as
     *                     allocated_days - SUM(days_count) over approved
     *                     leave_requests for the same (employee, type, year).
     *                     Encapsulated in LeaveBalanceQueryService. The
     *                     stored-vs-computed decision is documented in the
     *                     slice plan + hrm.md (correctness > performance at
     *                     SME scale; cache later if needed).
     *
     * Type subset CHECK locked to ('annual','sick'). Unpaid + other are
     * unbounded by design — no balance row, no display row, no SUM
     * contribution. Promoting 'other' to allocated later is additive
     * (add to CHECK + picker + seeder); demoting would be a cutover.
     */
    public function up(): void
    {
        Schema::create('leave_balances', function (Blueprint $t): void {
            $t->id();

            $t->foreignId('tenant_id')
                ->constrained('tenants')
                ->restrictOnDelete();
            $t->foreignId('company_id')
                ->constrained('companies')
                ->restrictOnDelete();

            // employee_id: same RESTRICT-on-delete shape as leave_requests.
            // Soft-deleted employees keep their balance rows for historical
            // visibility; the global Employee scope hides the employee
            // record itself.
            $t->foreignId('employee_id')
                ->constrained('employees')
                ->restrictOnDelete();

            // varchar(16) mirrors the leave_requests.leave_type column —
            // same enum source (LeaveType), but the balance table only
            // accepts the allocated subset via the CHECK below.
            $t->string('leave_type', 16);

            // period_year as integer — simple, indexable. Range CHECK
            // bounds it to a realistic envelope (no 1-digit "2026 typo"
            // landing in 26).
            $t->integer('period_year');

            // decimal(5,1) supports 0.0 to 9999.9 days. Real-world allocations
            // sit between 0 and ~30; the cap is irrelevant headroom.
            $t->decimal('allocated_days', 5, 1);

            $t->string('notes', 500)->nullable();

            $t->timestampsTz();
            $t->softDeletesTz();

            $t->index(['tenant_id', 'company_id', 'employee_id'], 'leave_balances_tenant_company_employee_idx');
            $t->index(['tenant_id', 'company_id', 'period_year'], 'leave_balances_tenant_company_period_idx');
        });

        // Partial unique index — one balance row per (tenant, company,
        // employee, leave_type, period_year). WHERE deleted_at IS NULL
        // so soft-deleted rows don't block re-creation. Same discipline
        // as branches/positions/attendance.
        DB::statement(
            'CREATE UNIQUE INDEX leave_balances_unique_employee_type_year
             ON leave_balances (tenant_id, company_id, employee_id, leave_type, period_year)
             WHERE deleted_at IS NULL'
        );

        // CHECK: leave_type restricted to the allocated subset.
        // Locked decision per slice plan — unpaid + other are unbounded,
        // have no balance row. The full LeaveType enum (annual/sick/
        // unpaid/other) stays the source of truth for leave_requests.
        DB::statement(
            "ALTER TABLE leave_balances ADD CONSTRAINT leave_balances_leave_type_check
             CHECK (leave_type IN ('annual','sick'))"
        );

        // CHECK: allocated_days >= 0. Negative allocations are nonsensical
        // (a company doesn't grant negative days). Remaining_days can be
        // negative (over-consumption) — but it's computed, not stored.
        DB::statement(
            'ALTER TABLE leave_balances ADD CONSTRAINT leave_balances_allocated_nonneg_check
             CHECK (allocated_days >= 0)'
        );

        // CHECK: period_year in [2000, 2100]. Catches the typo case
        // (e.g. period_year = 26 because someone meant 2026). Range is
        // generous — any realistic accounting year fits.
        DB::statement(
            'ALTER TABLE leave_balances ADD CONSTRAINT leave_balances_period_year_range_check
             CHECK (period_year BETWEEN 2000 AND 2100)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_balances');
    }
};
