<?php

declare(strict_types=1);

use App\Domain\HRM\Actions\UpdateAttendanceAction;
use App\Domain\HRM\Enums\AttendanceStatus;
use App\Domain\HRM\Models\AttendanceRecord;
use App\Domain\HRM\Models\Employee;
use App\Models\Company;
use App\Models\Tenant;
use App\Support\Audit\Models\AuditLog;
use App\Support\Company\CompanyContext;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    $this->company = Company::factory()->forTenant($this->tenant)->create();
    app(TenantContext::class)->setCurrent($this->tenant);
    app(CompanyContext::class)->setCurrent($this->company);

    $this->employee = Employee::factory()->forCompany($this->company)->create();
});

it('updates only the supplied fields and leaves the rest untouched', function (): void {
    $record = AttendanceRecord::factory()->forEmployee($this->employee)->create([
        'date' => '2026-05-14',
        'clock_in' => '09:00:00',
        'clock_out' => '18:00:00',
        'status' => AttendanceStatus::Present,
        'notes' => null,
    ]);

    $updated = app(UpdateAttendanceAction::class)
        ->execute($record, ['notes' => 'Followed up with HR.']);

    expect($updated->notes)->toBe('Followed up with HR.');
    expect($updated->status)->toBe(AttendanceStatus::Present);
    expect($updated->clock_in)->toBe('09:00:00');
    expect($updated->clock_out)->toBe('18:00:00');
});

it('writes a diff-only audit row capturing only the changed field', function (): void {
    $record = AttendanceRecord::factory()->forEmployee($this->employee)->create([
        'status' => AttendanceStatus::Present,
    ]);

    app(UpdateAttendanceAction::class)
        ->execute($record, ['status' => AttendanceStatus::Late->value]);

    $row = AuditLog::query()
        ->where('auditable_type', AttendanceRecord::class)
        ->where('auditable_id', $record->id)
        ->where('action', 'updated')
        ->latest('id')
        ->first();

    expect($row)->not->toBeNull();
    expect($row->before)->toEqual(['status' => 'present']);
    expect($row->after)->toEqual(['status' => 'late']);
});

it('LOAD-BEARING: raw INSERT with clock_out before clock_in is rejected by the composite DB CHECK', function (): void {
    // Bypass the model AND the FormRequest entirely — DB::table()->insert()
    // skips every application-layer guard. This is the regression-
    // protection test the user explicitly called out: it proves the
    // CHECK actually fires, not just that some higher layer happens to
    // catch the inconsistency first. Same pattern as the existing
    // leave_requests_day_part_single_date_check raw-insert test.
    $thrown = false;
    try {
        DB::table('attendance_records')->insert([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'date' => '2026-05-20',
            // The inconsistency: clock_out is BEFORE clock_in.
            'clock_in' => '18:00:00',
            'clock_out' => '09:00:00',
            'status' => 'present',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    } catch (QueryException $e) {
        $thrown = true;
        // Pin the constraint name so a future migration rename surfaces
        // as a test failure rather than a silent "wrong CHECK fired."
        expect($e->getMessage())->toContain('attendance_records_clock_order_check');
    }

    expect($thrown)->toBeTrue(
        'Expected the composite CHECK constraint to reject the inconsistent raw INSERT.',
    );
});

it('raw INSERT with only one clock time set is allowed (CHECK only fires when BOTH are non-null)', function (): void {
    // Documents the deliberate looseness of the CHECK: half-day rows
    // legitimately have only one clock time. Direct insert with one
    // null time should succeed.
    $id = DB::table('attendance_records')->insertGetId([
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->company->id,
        'employee_id' => $this->employee->id,
        'date' => '2026-05-21',
        'clock_in' => '09:00:00',
        'clock_out' => null,
        'status' => 'half_day',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect($id)->toBeGreaterThan(0);
});
