<?php

declare(strict_types=1);

use App\Domain\HRM\Actions\CreateLeaveRequestAction;
use App\Domain\HRM\Enums\LeaveRequestStatus;
use App\Domain\HRM\Enums\LeaveType;
use App\Domain\HRM\Models\Employee;
use App\Domain\HRM\Models\LeaveRequest;
use App\Models\Company;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Audit\Models\AuditLog;
use App\Support\Company\CompanyContext;
use App\Support\Tenancy\TenantContext;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    $this->company = Company::factory()->forTenant($this->tenant)->create();
    app(TenantContext::class)->setCurrent($this->tenant);
    app(CompanyContext::class)->setCurrent($this->company);

    $this->employee = Employee::factory()->forCompany($this->company)->create();
});

it('creates a leave_request with auto-filled tenant_id and company_id, forced to pending', function (): void {
    $request = app(CreateLeaveRequestAction::class)->execute([
        'employee_id' => $this->employee->id,
        'leave_type' => LeaveType::Annual->value,
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-05',
        'reason' => 'Family event.',
    ]);

    expect($request->id)->not->toBeNull();
    expect($request->tenant_id)->toBe($this->tenant->id);
    expect($request->company_id)->toBe($this->company->id);
    expect($request->employee_id)->toBe($this->employee->id);
    expect($request->status)->toBe(LeaveRequestStatus::Pending);
    expect($request->approved_by)->toBeNull();
    expect($request->approved_at)->toBeNull();
    expect($request->approver_note)->toBeNull();
});

it('forces status to pending even if caller smuggles status=approved into the data array', function (): void {
    // The FormRequest filters status at the HTTP boundary, but the Action
    // is also defensive — a misguided seeder or internal caller can't
    // sidestep the workflow. The before/after audit row will show
    // after.status='pending'.
    $request = app(CreateLeaveRequestAction::class)->execute([
        'employee_id' => $this->employee->id,
        'leave_type' => LeaveType::Sick->value,
        'start_date' => '2026-06-10',
        'end_date' => '2026-06-10',
        'reason' => null,
        // Smuggled — should be ignored.
        'status' => LeaveRequestStatus::Approved->value,
        'approved_by' => 999,
        'approved_at' => '2026-01-01 00:00:00',
        'approver_note' => 'should not stick',
    ]);

    expect($request->status)->toBe(LeaveRequestStatus::Pending);
    expect($request->approved_by)->toBeNull();
    expect($request->approved_at)->toBeNull();
    expect($request->approver_note)->toBeNull();
});

it('writes an audit row with non-null tenant_id and company_id on create', function (): void {
    $request = app(CreateLeaveRequestAction::class)->execute([
        'employee_id' => $this->employee->id,
        'leave_type' => LeaveType::Unpaid->value,
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-03',
        'reason' => null,
    ]);

    $row = AuditLog::query()
        ->where('auditable_type', LeaveRequest::class)
        ->where('auditable_id', $request->id)
        ->where('action', 'created')
        ->first();

    expect($row)->not->toBeNull();
    expect($row->tenant_id)->toBe($this->tenant->id);
    expect($row->company_id)->toBe($this->company->id);
});

it('captures the authenticated user as actor_id in the audit row', function (): void {
    $user = User::factory()->forTenant($this->tenant)->create();
    $this->actingAs($user);

    $request = app(CreateLeaveRequestAction::class)->execute([
        'employee_id' => $this->employee->id,
        'leave_type' => LeaveType::Other->value,
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-01',
        'reason' => null,
    ]);

    $row = AuditLog::query()
        ->where('auditable_type', LeaveRequest::class)
        ->where('auditable_id', $request->id)
        ->first();

    expect($row->actor_id)->toBe($user->id);
});
