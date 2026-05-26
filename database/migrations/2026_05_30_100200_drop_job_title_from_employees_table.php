<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drop the free-text employees.job_title column. The structured
     * position_id FK (added in the prior migration
     * 2026_05_30_100100_add_position_id_to_employees_table.php)
     * replaces it.
     *
     * ⚠️ PRODUCTION WARNING ⚠️
     *
     * Do NOT run this migration in production until the per-tenant
     * data-migration command has populated position_id for every
     * existing employee. The sequence is:
     *
     *   1. Run 2026_05_30_100100_add_position_id_to_employees_table.php
     *      (purely additive — leaves position_id NULL on all existing rows)
     *
     *   2. Run the per-tenant data-migration command (see hrm.md
     *      Production migration sequence) — this iterates each tenant's
     *      distinct job_title values, creates Position records, sets
     *      position_id on employees
     *
     *   3. Verify position_id is populated for every employee that
     *      previously had a job_title
     *
     *   4. ONLY THEN run THIS migration
     *
     * Running steps 1 + 4 consecutively without step 2 LOSES all
     * job_title values irrecoverably from production. The dev workflow
     * is safe because migrate:fresh + DemoUsersSeeder re-creates
     * everything; production has no equivalent reset.
     */
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $t): void {
            $t->dropColumn('job_title');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $t): void {
            // The reverse-migration restores the column as nullable; the
            // values themselves are lost — recovering them requires a
            // separate per-row SELECT/UPDATE from positions.title (see
            // hrm.md "Rollback" subsection).
            $t->string('job_title', 255)->nullable()->after('email');
        });
    }
};
