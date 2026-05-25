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
        Schema::create('leave_requests', function (Blueprint $t): void {
            $t->id();

            $t->foreignId('tenant_id')
                ->constrained('tenants')
                ->restrictOnDelete();
            $t->foreignId('company_id')
                ->constrained('companies')
                ->restrictOnDelete();

            // employee_id: FK to employees, RESTRICT on hard-delete to keep
            // leave history intact. Soft-deleted employees still have their
            // request rows visible (the global Employee scope hides the
            // employee row from the wire, but the FK is just an id pointing
            // at the tombstone). Same scoped-exists validation in the
            // FormRequest as Employee.department_id — load-bearing.
            $t->foreignId('employee_id')
                ->constrained('employees')
                ->restrictOnDelete();

            $t->string('leave_type', 16);
            $t->date('start_date');
            $t->date('end_date');
            $t->string('reason', 500)->nullable();

            // status defaults to 'pending' at the DB level so a missing
            // value on insert lands in the right state. The Action also
            // force-sets pending on create — belt and suspenders.
            $t->string('status', 16)->default('pending');

            // Approval columns — must be set together with a non-pending
            // status. The composite CHECK below enforces consistency.
            // approved_by → users(id) ON DELETE SET NULL: if a user is
            // hard-deleted the approval row keeps the decision (status +
            // approved_at), just loses the actor name. The audit log
            // retains the actor regardless.
            $t->foreignId('approved_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $t->timestampTz('approved_at')->nullable();
            $t->string('approver_note', 500)->nullable();

            $t->timestampsTz();
            $t->softDeletesTz();

            $t->index(['tenant_id', 'company_id', 'employee_id'], 'leave_requests_tenant_company_employee_idx');
            $t->index(['tenant_id', 'company_id', 'status'], 'leave_requests_tenant_company_status_idx');
            // Supports the "this employee's leave history" + date-window queries.
            $t->index(['tenant_id', 'company_id', 'start_date'], 'leave_requests_tenant_company_start_date_idx');
        });

        // Partial index on approved_by — supports the "all requests approved
        // by user X" query path. WHERE clause keeps it small; pending rows
        // (approved_by IS NULL) don't bloat the index.
        DB::statement(
            'CREATE INDEX leave_requests_approved_by_idx
             ON leave_requests (approved_by)
             WHERE approved_by IS NOT NULL'
        );

        // CHECK on leave_type enum — defense in depth against direct SQL.
        DB::statement(
            "ALTER TABLE leave_requests ADD CONSTRAINT leave_requests_leave_type_check
             CHECK (leave_type IN ('annual','sick','unpaid','other'))"
        );

        // CHECK on status enum.
        DB::statement(
            "ALTER TABLE leave_requests ADD CONSTRAINT leave_requests_status_check
             CHECK (status IN ('pending','approved','rejected'))"
        );

        // CHECK end_date >= start_date — catches client + factory bugs
        // before they persist garbage. The Zod schema and FormRequest
        // also enforce this; three places, one rule.
        DB::statement(
            'ALTER TABLE leave_requests ADD CONSTRAINT leave_requests_dates_check
             CHECK (end_date >= start_date)'
        );

        // Composite CHECK on (status, approved_by, approved_at) — the
        // load-bearing one. A pending row MUST have null approval columns;
        // a decided row MUST have both approval columns populated. Catches
        // bad seeders, console fixes, and any future bug that touches one
        // column without the other.
        //
        // The Approve/Reject Actions enforce the same invariant at the
        // application layer (they set all three columns in one save), and
        // the seeder enforces it via direct construction in the same INSERT
        // (which is why the seeder is allowed to write decided rows
        // directly instead of routing through the Actions — the audit log
        // would otherwise carry a "seed-time approval" event with no actor,
        // misleading workflow history).
        DB::statement(
            "ALTER TABLE leave_requests ADD CONSTRAINT leave_requests_approval_consistency_check
             CHECK (
                (status = 'pending'  AND approved_by IS NULL AND approved_at IS NULL)
             OR (status <> 'pending' AND approved_by IS NOT NULL AND approved_at IS NOT NULL)
             )"
        );
    }

    public function down(): void
    {
        // Forward-only in production (§7.E); down() exists for migrate:fresh symmetry only.
        Schema::dropIfExists('leave_requests');
    }
};
