<?php

declare(strict_types=1);

use App\Domain\HRM\Actions\RejectLeaveRequestAction;
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
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    $this->company = Company::factory()->forTenant($this->tenant)->create();
    app(TenantContext::class)->setCurrent($this->tenant);
    app(CompanyContext::class)->setCurrent($this->company);

    $this->employee = Employee::factory()->forCompany($this->company)->create();
    $this->manager = User::factory()->forTenant($this->tenant)->create();
});

it('transitions pending → rejected and sets all three approval columns in lockstep', function (): void {
    $request = LeaveRequest::factory()->forEmployee($this->employee)->create([
        'leave_type' => LeaveType::Annual,
    ]);

    $rejected = app(RejectLeaveRequestAction::class)
        ->execute($request, 'Capacity full this week.', $this->manager->id);

    expect($rejected->status)->toBe(LeaveRequestStatus::Rejected);
    expect($rejected->approved_by)->toBe($this->manager->id);
    expect($rejected->approved_at)->not->toBeNull();
    expect($rejected->approver_note)->toBe('Capacity full this week.');
});

it('throws InvalidLeaveRequestTransitionException with from=approved, to=rejected when rejecting an approved request', function (): void {
    $request = LeaveRequest::factory()
        ->forEmployee($this->employee)
        ->approved($this->manager)
        ->create();

    try {
        app(RejectLeaveRequestAction::class)
            ->execute($request, 'changed my mind', $this->manager->id);
        $this->fail('Expected InvalidLeaveRequestTransitionException.');
    } catch (InvalidLeaveRequestTransitionException $e) {
        expect($e->from)->toBe(LeaveRequestStatus::Approved);
        expect($e->to)->toBe(LeaveRequestStatus::Rejected);
        expect($e->getMessage())->toContain('approved');
    }
});

it('writes an audit row with the status flip from pending to rejected', function (): void {
    $request = LeaveRequest::factory()->forEmployee($this->employee)->create();

    app(RejectLeaveRequestAction::class)
        ->execute($request, 'no', $this->manager->id);

    $row = AuditLog::query()
        ->where('auditable_type', LeaveRequest::class)
        ->where('auditable_id', $request->id)
        ->where('action', 'updated')
        ->latest('id')
        ->first();

    expect($row)->not->toBeNull();
    expect($row->before['status'] ?? null)->toBe('pending');
    expect($row->after['status'] ?? null)->toBe('rejected');
});

it('rejects a manual UPDATE that leaves status=approved with null approval columns — the composite DB CHECK guards inconsistency', function (): void {
    // This is the seeder/CHECK guard regression test. The factory's
    // approved()/rejected() states write all three columns; a future
    // change that splits them (e.g. "set status now, fill columns
    // later") would be silently wrong. The DB CHECK catches it.
    //
    // We simulate a bad seeder write by going around the model and
    // doing a raw UPDATE that violates the invariant.
    $request = LeaveRequest::factory()->forEmployee($this->employee)->create();

    $thrown = false;
    try {
        DB::table('leave_requests')
            ->where('id', $request->id)
            ->update([
                'status' => 'approved',
                // Deliberately leaving approved_by and approved_at NULL —
                // violates the composite CHECK.
            ]);
    } catch (QueryException $e) {
        $thrown = true;
        // PG CHECK violation surfaces in the message.
        expect($e->getMessage())->toContain('leave_requests_approval_consistency_check');
    }

    expect($thrown)->toBeTrue('Expected the composite CHECK constraint to reject the inconsistent UPDATE.');
});
