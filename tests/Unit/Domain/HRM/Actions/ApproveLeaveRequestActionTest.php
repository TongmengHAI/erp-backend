<?php

declare(strict_types=1);

use App\Domain\HRM\Actions\ApproveLeaveRequestAction;
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

it('transitions pending → approved and sets all three approval columns in lockstep', function (): void {
    $request = LeaveRequest::factory()->forEmployee($this->employee)->create([
        'leave_type' => LeaveType::Annual,
    ]);

    $approved = app(ApproveLeaveRequestAction::class)
        ->execute($request, 'Looks good.', $this->manager->id);

    expect($approved->status)->toBe(LeaveRequestStatus::Approved);
    expect($approved->approved_by)->toBe($this->manager->id);
    expect($approved->approved_at)->not->toBeNull();
    expect($approved->approver_note)->toBe('Looks good.');
});

it('accepts a null note (note is optional at the action layer)', function (): void {
    $request = LeaveRequest::factory()->forEmployee($this->employee)->create();

    $approved = app(ApproveLeaveRequestAction::class)
        ->execute($request, null, $this->manager->id);

    expect($approved->status)->toBe(LeaveRequestStatus::Approved);
    expect($approved->approver_note)->toBeNull();
    // Still satisfies the composite DB CHECK because approved_by and
    // approved_at ARE set — only the note is nullable.
    expect($approved->approved_by)->toBe($this->manager->id);
    expect($approved->approved_at)->not->toBeNull();
});

it('throws InvalidLeaveRequestTransitionException with from=approved, to=approved when approving an already-approved request', function (): void {
    $request = LeaveRequest::factory()
        ->forEmployee($this->employee)
        ->approved($this->manager)
        ->create();

    try {
        app(ApproveLeaveRequestAction::class)
            ->execute($request, 'second time', $this->manager->id);
        $this->fail('Expected InvalidLeaveRequestTransitionException.');
    } catch (InvalidLeaveRequestTransitionException $e) {
        expect($e->from)->toBe(LeaveRequestStatus::Approved);
        expect($e->to)->toBe(LeaveRequestStatus::Approved);
        expect($e->getMessage())->toContain('approved');
    }
});

it('throws InvalidLeaveRequestTransitionException with from=rejected, to=approved when approving a rejected request', function (): void {
    $request = LeaveRequest::factory()
        ->forEmployee($this->employee)
        ->rejected($this->manager)
        ->create();

    try {
        app(ApproveLeaveRequestAction::class)
            ->execute($request, null, $this->manager->id);
        $this->fail('Expected InvalidLeaveRequestTransitionException.');
    } catch (InvalidLeaveRequestTransitionException $e) {
        expect($e->from)->toBe(LeaveRequestStatus::Rejected);
        expect($e->to)->toBe(LeaveRequestStatus::Approved);
    }
});

it('writes an audit row with the status flip from pending to approved', function (): void {
    $request = LeaveRequest::factory()->forEmployee($this->employee)->create();

    app(ApproveLeaveRequestAction::class)
        ->execute($request, 'ok', $this->manager->id);

    $row = AuditLog::query()
        ->where('auditable_type', LeaveRequest::class)
        ->where('auditable_id', $request->id)
        ->where('action', 'updated')
        ->latest('id')
        ->first();

    expect($row)->not->toBeNull();
    expect($row->before['status'] ?? null)->toBe('pending');
    expect($row->after['status'] ?? null)->toBe('approved');
});
