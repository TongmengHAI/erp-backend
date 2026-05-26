<?php

declare(strict_types=1);

use App\Domain\HRM\Actions\CreateLeaveBalanceAction;
use App\Domain\HRM\Enums\LeaveType;
use App\Domain\HRM\Models\Employee;
use App\Domain\HRM\Models\LeaveBalance;
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

it('persists a leave_balance with auto-filled tenant_id + company_id from context', function (): void {
    $balance = app(CreateLeaveBalanceAction::class)->execute([
        'employee_id' => $this->employee->id,
        'leave_type' => LeaveType::Annual->value,
        'period_year' => 2026,
        'allocated_days' => 14.0,
        'notes' => 'Standard annual allocation.',
    ]);

    expect($balance->id)->not->toBeNull();
    expect($balance->tenant_id)->toBe($this->tenant->id);
    expect($balance->company_id)->toBe($this->company->id);
    expect($balance->employee_id)->toBe($this->employee->id);
    expect($balance->leave_type)->toBe(LeaveType::Annual);
    expect($balance->period_year)->toBe(2026);
    expect((float) $balance->allocated_days)->toBe(14.0);
});

it('writes an audit row with non-null tenant_id + company_id on create', function (): void {
    $user = User::factory()->forTenant($this->tenant)->create();
    $this->actingAs($user);

    $balance = app(CreateLeaveBalanceAction::class)->execute([
        'employee_id' => $this->employee->id,
        'leave_type' => LeaveType::Sick->value,
        'period_year' => 2026,
        'allocated_days' => 7.0,
        'notes' => null,
    ]);

    $row = AuditLog::query()
        ->where('auditable_type', LeaveBalance::class)
        ->where('auditable_id', $balance->id)
        ->where('action', 'created')
        ->first();

    expect($row)->not->toBeNull();
    expect($row->tenant_id)->toBe($this->tenant->id);
    expect($row->company_id)->toBe($this->company->id);
    expect($row->actor_id)->toBe($user->id);
});
