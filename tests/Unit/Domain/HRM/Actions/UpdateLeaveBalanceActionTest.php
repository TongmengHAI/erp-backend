<?php

declare(strict_types=1);

use App\Domain\HRM\Actions\UpdateLeaveBalanceAction;
use App\Domain\HRM\Enums\LeaveType;
use App\Domain\HRM\Models\Employee;
use App\Domain\HRM\Models\LeaveBalance;
use App\Models\Company;
use App\Models\Tenant;
use App\Support\Audit\Models\AuditLog;
use App\Support\Company\CompanyContext;
use App\Support\Tenancy\TenantContext;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    $this->company = Company::factory()->forTenant($this->tenant)->create();
    app(TenantContext::class)->setCurrent($this->tenant);
    app(CompanyContext::class)->setCurrent($this->company);

    $this->employee = Employee::factory()->forCompany($this->company)->create();
    $this->balance = LeaveBalance::factory()->forEmployee($this->employee)->create([
        'leave_type' => LeaveType::Annual,
        'period_year' => 2026,
        'allocated_days' => 14.0,
        'notes' => null,
    ]);
});

it('updates only the supplied fields and leaves the rest untouched', function (): void {
    $updated = app(UpdateLeaveBalanceAction::class)->execute($this->balance, [
        'allocated_days' => 16.0,
    ]);

    expect((float) $updated->allocated_days)->toBe(16.0);
    // Identity tuple untouched.
    expect($updated->leave_type)->toBe(LeaveType::Annual);
    expect($updated->period_year)->toBe(2026);
    expect($updated->employee_id)->toBe($this->employee->id);
});

it('writes a diff-only audit row capturing just the changed fields', function (): void {
    app(UpdateLeaveBalanceAction::class)->execute($this->balance, [
        'notes' => 'Updated allocation reasoning.',
    ]);

    $row = AuditLog::query()
        ->where('auditable_type', LeaveBalance::class)
        ->where('auditable_id', $this->balance->id)
        ->where('action', 'updated')
        ->latest('id')
        ->first();

    expect($row)->not->toBeNull();
    // Diff-only: allocated_days was NOT changed, must not appear.
    expect($row->before)->toEqual(['notes' => null]);
    expect($row->after)->toEqual(['notes' => 'Updated allocation reasoning.']);
});
