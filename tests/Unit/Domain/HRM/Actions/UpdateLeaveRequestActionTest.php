<?php

declare(strict_types=1);

use App\Domain\HRM\Actions\UpdateLeaveRequestAction;
use App\Domain\HRM\Enums\DayPart;
use App\Domain\HRM\Enums\LeaveRequestStatus;
use App\Domain\HRM\Enums\LeaveType;
use App\Domain\HRM\Exceptions\InvalidLeaveRequestTransitionException;
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
    $this->manager = User::factory()->forTenant($this->tenant)->create();
});

it('updates non-status fields on a pending request and writes a diff-only audit row', function (): void {
    $request = LeaveRequest::factory()->forEmployee($this->employee)->create([
        'leave_type' => LeaveType::Annual,
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-05',
        'reason' => 'Old reason.',
    ]);

    $updated = app(UpdateLeaveRequestAction::class)->execute($request, [
        'reason' => 'New reason.',
        'end_date' => '2026-06-07',
    ]);

    expect($updated->reason)->toBe('New reason.');
    expect($updated->end_date->toDateString())->toBe('2026-06-07');
    expect($updated->status)->toBe(LeaveRequestStatus::Pending);

    $row = AuditLog::query()
        ->where('auditable_type', LeaveRequest::class)
        ->where('auditable_id', $request->id)
        ->where('action', 'updated')
        ->latest('id')
        ->first();

    expect($row)->not->toBeNull();
    // Diff-only: only the changed fields appear.
    expect($row->before)->toHaveKey('reason');
    expect($row->before)->toHaveKey('end_date');
    expect($row->after)->toHaveKey('reason');
    expect($row->after)->toHaveKey('end_date');
});

it('recomputes days_count when end_date changes (5 → 7 day span)', function (): void {
    $request = LeaveRequest::factory()->forEmployee($this->employee)->create([
        'leave_type' => LeaveType::Annual,
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-05',
        'day_part' => DayPart::FullDay,
    ]);
    expect((float) $request->days_count)->toBe(5.0);

    $updated = app(UpdateLeaveRequestAction::class)->execute($request, [
        'end_date' => '2026-06-07',
    ]);

    expect((float) $updated->days_count)->toBe(7.0);
});

it('recomputes days_count when day_part flips to morning (3 days full → 0.5 morning)', function (): void {
    $request = LeaveRequest::factory()->forEmployee($this->employee)->create([
        'leave_type' => LeaveType::Sick,
        'start_date' => '2026-06-10',
        'end_date' => '2026-06-12',
        'day_part' => DayPart::FullDay,
    ]);
    expect((float) $request->days_count)->toBe(3.0);

    $updated = app(UpdateLeaveRequestAction::class)->execute($request, [
        // Morning forces start == end, so the patch collapses end_date too.
        'end_date' => '2026-06-10',
        'day_part' => DayPart::Morning->value,
    ]);

    expect((float) $updated->days_count)->toBe(0.5);
});

it('does NOT recompute days_count when only reason changes (editing reason leaves days_count untouched)', function (): void {
    $request = LeaveRequest::factory()->forEmployee($this->employee)->create([
        'leave_type' => LeaveType::Annual,
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-05',
        'day_part' => DayPart::FullDay,
        'reason' => 'Old reason.',
    ]);
    $beforeDays = (float) $request->days_count;

    $updated = app(UpdateLeaveRequestAction::class)->execute($request, [
        'reason' => 'New reason.',
    ]);

    expect((float) $updated->days_count)->toBe($beforeDays);

    // And the audit diff must not list days_count as a changed field.
    $row = AuditLog::query()
        ->where('auditable_type', LeaveRequest::class)
        ->where('auditable_id', $request->id)
        ->where('action', 'updated')
        ->latest('id')
        ->first();

    expect($row->before)->not->toHaveKey('days_count');
    expect($row->after)->not->toHaveKey('days_count');
});

it('throws InvalidLeaveRequestTransitionException with from=to=approved when editing an approved request', function (): void {
    $request = LeaveRequest::factory()
        ->forEmployee($this->employee)
        ->approved($this->manager, 'looks good')
        ->create([
            'leave_type' => LeaveType::Annual,
            'reason' => 'Already approved.',
        ]);

    try {
        app(UpdateLeaveRequestAction::class)->execute($request, ['reason' => 'try to change']);
        $this->fail('Expected InvalidLeaveRequestTransitionException.');
    } catch (InvalidLeaveRequestTransitionException $e) {
        // The action throws with from=to=current to signal "you're trying
        // to act on a state that forbids action" rather than a true
        // transition. The exception's render() will surface both fields.
        expect($e->from)->toBe(LeaveRequestStatus::Approved);
        expect($e->to)->toBe(LeaveRequestStatus::Approved);
        expect($e->getMessage())->toContain('approved');
    }
});

it('throws InvalidLeaveRequestTransitionException when editing a rejected request', function (): void {
    $request = LeaveRequest::factory()
        ->forEmployee($this->employee)
        ->rejected($this->manager, 'no')
        ->create();

    try {
        app(UpdateLeaveRequestAction::class)->execute($request, ['reason' => 'try to change']);
        $this->fail('Expected InvalidLeaveRequestTransitionException.');
    } catch (InvalidLeaveRequestTransitionException $e) {
        expect($e->from)->toBe(LeaveRequestStatus::Rejected);
        expect($e->to)->toBe(LeaveRequestStatus::Rejected);
    }
});

it('does not write an audit row when the edit is rejected by the pending-only guard', function (): void {
    $request = LeaveRequest::factory()
        ->forEmployee($this->employee)
        ->approved($this->manager)
        ->create();

    $countBefore = AuditLog::query()
        ->where('auditable_type', LeaveRequest::class)
        ->where('auditable_id', $request->id)
        ->where('action', 'updated')
        ->count();

    try {
        app(UpdateLeaveRequestAction::class)->execute($request, ['reason' => 'try']);
    } catch (InvalidLeaveRequestTransitionException) {
        // Expected.
    }

    $countAfter = AuditLog::query()
        ->where('auditable_type', LeaveRequest::class)
        ->where('auditable_id', $request->id)
        ->where('action', 'updated')
        ->count();

    // Guard runs before save() — no audit row should be written.
    expect($countAfter)->toBe($countBefore);
});
