<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add day_part to leave_requests for half-day granularity.
     *
     * Non-destructive: NOT NULL DEFAULT 'full_day' so existing rows
     * land in the expected state without a backfill step. Any leave
     * request created before this migration is, by historical
     * definition, a full-day request — the new column reflects that
     * truthfully.
     *
     * Two new CHECK constraints:
     *   - Enum CHECK on the day_part column itself (the standard
     *     belt-and-suspenders pattern from leave_type and status).
     *   - Composite CHECK enforcing the single-date invariant for
     *     half-day requests: day_part='full_day' OR start_date=end_date.
     *     This is the load-bearing one — application/FormRequest could
     *     drift but the DB rejects an inconsistent row regardless.
     *     Same triple-stack pattern as the existing
     *     leave_requests_approval_consistency_check.
     */
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $t): void {
            // Positioned after end_date so column ordering reads naturally
            // in tooling (psql \d, GUI inspectors): leave_type → start_date
            // → end_date → day_part → reason. Postgres doesn't strictly
            // care, but human readers do.
            $t->string('day_part', 16)->default('full_day')->after('end_date');
        });

        DB::statement(
            "ALTER TABLE leave_requests ADD CONSTRAINT leave_requests_day_part_check
             CHECK (day_part IN ('full_day','morning','afternoon'))"
        );

        // Composite CHECK — half-day requests collapse to a single date.
        // The Zod refinement and the FormRequest closure also enforce
        // this; the DB layer is the final guard against direct SQL,
        // migration bugs in future date-shifting scripts, and any
        // careless seeder that bypasses both higher layers.
        DB::statement(
            "ALTER TABLE leave_requests ADD CONSTRAINT leave_requests_day_part_single_date_check
             CHECK (day_part = 'full_day' OR start_date = end_date)"
        );
    }

    public function down(): void
    {
        // Forward-only in production (§7.E); down() exists for migrate:fresh symmetry only.
        DB::statement('ALTER TABLE leave_requests DROP CONSTRAINT IF EXISTS leave_requests_day_part_single_date_check');
        DB::statement('ALTER TABLE leave_requests DROP CONSTRAINT IF EXISTS leave_requests_day_part_check');
        Schema::table('leave_requests', function (Blueprint $t): void {
            $t->dropColumn('day_part');
        });
    }
};
