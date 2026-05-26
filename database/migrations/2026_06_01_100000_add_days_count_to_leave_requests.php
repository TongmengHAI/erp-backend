<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add days_count to leave_requests — stored decimal, recomputed
     * by the Action on every create/update where a date-or-day_part
     * field changes. Single source of truth that the upcoming Leave
     * Balances slice's SUM aggregate (SUM(days_count) WHERE
     * status='approved') can use directly.
     *
     * Three-statement atomic pattern in up():
     *
     *   1. Add the column NULLABLE so existing rows don't blow up
     *      on the immediate insert path.
     *   2. UPDATE all existing rows via raw SQL whose expression is
     *      byte-equivalent to LeaveDaysCalculator::compute():
     *        day_part = 'full_day'  → end_date - start_date + 1
     *        day_part in ('morning','afternoon') → 0.5
     *      The equivalence is asserted by the
     *      MigrationBackfillEqualsCalculatorTest unit test, which is
     *      the discipline that lets the SQL above and the PHP
     *      calculator coexist safely.
     *   3. SET NOT NULL once every row is populated.
     *
     * down() doesn't reverse the SET NOT NULL — straight column drop
     * is the simplest no-op-for-prod path (forward-only in production
     * per §7.E; down() exists for migrate:fresh symmetry only).
     */
    public function up(): void
    {
        // 1. Add nullable column.
        Schema::table('leave_requests', function (Blueprint $t): void {
            // decimal(5,1) supports any leave length from 0.5 to 9999.9 days.
            // No realistic leave request approaches the upper bound — the
            // shape just leaves room without over-spending bytes.
            $t->decimal('days_count', 5, 1)->nullable()->after('day_part');
        });

        // 2. Backfill via raw SQL — byte-equivalent to LeaveDaysCalculator.
        //    Postgres date arithmetic: (end_date - start_date) is integer
        //    days, so adding 1 yields the inclusive span. Cast to numeric
        //    for the decimal column.
        DB::statement(
            "UPDATE leave_requests
             SET days_count = CASE
                 WHEN day_part IN ('morning', 'afternoon') THEN 0.5
                 ELSE ((end_date - start_date) + 1)::numeric(5,1)
             END"
        );

        // 3. Lock NOT NULL now that every row has a value.
        DB::statement('ALTER TABLE leave_requests ALTER COLUMN days_count SET NOT NULL');

        // CHECK: days_count must be strictly positive. The calculator
        // never returns 0 or negative, but defense in depth — direct
        // SQL or a future buggy seeder shouldn't slip past.
        DB::statement(
            'ALTER TABLE leave_requests ADD CONSTRAINT leave_requests_days_count_positive_check
             CHECK (days_count > 0)'
        );
    }

    public function down(): void
    {
        // Forward-only in production (§7.E); down() exists for migrate:fresh symmetry only.
        DB::statement('ALTER TABLE leave_requests DROP CONSTRAINT IF EXISTS leave_requests_days_count_positive_check');
        Schema::table('leave_requests', function (Blueprint $t): void {
            $t->dropColumn('days_count');
        });
    }
};
