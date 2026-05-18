<?php

declare(strict_types=1);

use App\Domain\HRM\Actions\UpdateEmployeeAction;
use App\Domain\HRM\Enums\EmployeeStatus;
use App\Domain\HRM\Models\Employee;
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

    $this->employee = Employee::factory()->forCompany($this->company)->create([
        'job_title' => 'Junior Clerk',
        'status' => EmployeeStatus::Active,
    ]);
});

it('updates only the supplied fields and leaves the rest untouched', function (): void {
    $originalName = $this->employee->full_name;
    $originalCode = $this->employee->employee_code;

    $updated = app(UpdateEmployeeAction::class)
        ->execute($this->employee, ['job_title' => 'Senior Clerk']);

    expect($updated->job_title)->toBe('Senior Clerk');
    expect($updated->full_name)->toBe($originalName);
    expect($updated->employee_code)->toBe($originalCode);
});

it('writes an audit row with a diff-only before/after of just the changed fields', function (): void {
    app(UpdateEmployeeAction::class)
        ->execute($this->employee, ['status' => EmployeeStatus::OnLeave->value]);

    $row = AuditLog::query()
        ->where('auditable_type', Employee::class)
        ->where('auditable_id', $this->employee->id)
        ->where('action', 'updated')
        ->latest('id')
        ->first();

    expect($row)->not->toBeNull();
    expect($row->tenant_id)->toBe($this->tenant->id);
    expect($row->company_id)->toBe($this->company->id);
    // Diff-only: only `status` should appear in before/after, NOT untouched
    // fields like full_name or hire_date.
    expect($row->before)->toEqual(['status' => 'active']);
    expect($row->after)->toEqual(['status' => 'on_leave']);
});

it('returns the refreshed model so casts (Carbon hire_date, enum status) reflect the post-save state', function (): void {
    $updated = app(UpdateEmployeeAction::class)
        ->execute($this->employee, [
            'status' => EmployeeStatus::Terminated->value,
            'hire_date' => '2020-01-01',
        ]);

    expect($updated->status)->toBe(EmployeeStatus::Terminated);
    // hire_date casts to Carbon — confirms the refresh worked.
    expect($updated->hire_date->toDateString())->toBe('2020-01-01');
});
