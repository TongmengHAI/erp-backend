<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Composite partial index supporting the Leave Balance SUM aggregate.
     *
     * The balance list query does:
     *
     *   LEFT JOIN (
     *     SELECT tenant_id, company_id, employee_id, leave_type,
     *            EXTRACT(YEAR FROM start_date) AS period_year,
     *            SUM(days_count) AS total_days
     *     FROM leave_requests
     *     WHERE status = 'approved' AND deleted_at IS NULL
     *     GROUP BY tenant_id, company_id, employee_id, leave_type,
     *              EXTRACT(YEAR FROM start_date)
     *   ) consumed ON ...
     *
     * This index narrows the scan to approved rows (~10% of all
     * leave_requests in practice) and gives the GROUP BY a covering
     * leading-column order. Sub-millisecond at SME scale; the partial
     * WHERE keeps the index small (excludes pending/rejected + soft-
     * deleted rows that the aggregate filters out anyway).
     */
    public function up(): void
    {
        DB::statement(
            "CREATE INDEX leave_requests_balance_lookup_idx
             ON leave_requests (tenant_id, company_id, employee_id, leave_type, start_date)
             WHERE status = 'approved' AND deleted_at IS NULL"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS leave_requests_balance_lookup_idx');
    }
};
