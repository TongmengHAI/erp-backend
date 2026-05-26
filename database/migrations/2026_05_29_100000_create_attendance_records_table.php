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
        Schema::create('attendance_records', function (Blueprint $t): void {
            $t->id();

            $t->foreignId('tenant_id')
                ->constrained('tenants')
                ->restrictOnDelete();
            $t->foreignId('company_id')
                ->constrained('companies')
                ->restrictOnDelete();

            // employee_id: FK to employees, RESTRICT on hard-delete to keep
            // attendance history intact. Soft-deleted employees still have
            // their attendance rows visible (the global Employee scope hides
            // the parent row but the FK still points at the tombstone). The
            // FormRequest's scoped-exists check rejects writes that reference
            // a soft-deleted employee — same pattern as leave_requests.
            $t->foreignId('employee_id')
                ->constrained('employees')
                ->restrictOnDelete();

            // The calendar date this record describes. NOT NULL — every
            // attendance row is for exactly one date.
            $t->date('date');

            // clock_in / clock_out are nullable because:
            //   - status=absent → no clock times at all
            //   - status=on_leave → no clock times
            //   - status=present / late → both times typical
            //   - status=half_day → could be either AM or PM only
            // The status-vs-clock cross-rule is deliberately NOT enforced
            // at the DB level for this slice — the admin records what
            // happened, and overriding clocks on an absent day (e.g. they
            // came in briefly then left) shouldn't be blocked. Add as a
            // FormRequest validation later if data shows it's needed.
            $t->time('clock_in')->nullable();
            $t->time('clock_out')->nullable();

            $t->string('status', 16);

            $t->string('notes', 500)->nullable();

            $t->timestampsTz();
            $t->softDeletesTz();

            // Standard index trio matching the query shapes from §6:
            //   - per-employee history (date desc default)
            //   - date-range filters (the index endpoint's `from`/`to`)
            //   - status filters (manager's "show all absences this week")
            $t->index(['tenant_id', 'company_id', 'employee_id', 'date'], 'attendance_records_tenant_company_employee_date_idx');
            $t->index(['tenant_id', 'company_id', 'date'], 'attendance_records_tenant_company_date_idx');
            $t->index(['tenant_id', 'company_id', 'status'], 'attendance_records_tenant_company_status_idx');
        });

        // Composite UNIQUE on (tenant, company, employee, date) — one
        // record per employee per day. Partial WHERE deleted_at IS NULL
        // so a soft-deleted row doesn't block re-creating for the same
        // (employee, date) combo. Standard "soft-delete then re-create"
        // workflow that the FormRequest's Rule::unique()->whereNull pattern
        // also assumes. The user-facing 422 with the named-fields error
        // ("Attendance for {employee} on {date} already exists.") catches
        // this at the request layer; this index is the DB backstop.
        DB::statement(
            'CREATE UNIQUE INDEX attendance_records_unique_employee_date
             ON attendance_records (tenant_id, company_id, employee_id, date)
             WHERE deleted_at IS NULL'
        );

        // CHECK on status enum — same belt-and-suspenders pattern as
        // leave_requests_status_check.
        DB::statement(
            "ALTER TABLE attendance_records ADD CONSTRAINT attendance_records_status_check
             CHECK (status IN ('present','absent','late','on_leave','half_day'))"
        );

        // CHECK: clock_out cannot precede clock_in when BOTH are set.
        // Loose by design — either being null is fine (the manager
        // recorded only one side of the day, or the status doesn't
        // need both). Only fires when there's actual contradiction.
        //
        // This is the LOAD-BEARING constraint the raw-insert regression
        // test (per the user's clarification) hits with DB::table()->insert()
        // bypassing the model + FormRequest entirely. The test passes
        // only if the CHECK itself fires — proves we're not catching
        // bad rows at a higher layer and accidentally claiming the DB
        // is enforcing the invariant.
        DB::statement(
            'ALTER TABLE attendance_records ADD CONSTRAINT attendance_records_clock_order_check
             CHECK (clock_out IS NULL OR clock_in IS NULL OR clock_out >= clock_in)'
        );
    }

    public function down(): void
    {
        // Forward-only in production (§7.E); down() exists for migrate:fresh symmetry only.
        Schema::dropIfExists('attendance_records');
    }
};
