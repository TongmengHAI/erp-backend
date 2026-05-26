<?php

declare(strict_types=1);

use App\Domain\HRM\Actions\CreateEmployeeAction;
use App\Domain\HRM\Enums\EmployeeStatus;
use App\Domain\HRM\Models\Employee;
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
});

it('persists an employee with the auto-filled tenant_id and company_id from context', function (): void {
    $employee = app(CreateEmployeeAction::class)->execute([
        'employee_code' => 'E-1234',
        'full_name' => 'Sokha Chan',
        'email' => 'sokha@example.test',
        'hire_date' => '2025-01-15',
        'status' => EmployeeStatus::Active->value,
    ]);

    expect($employee->id)->not->toBeNull();
    expect($employee->tenant_id)->toBe($this->tenant->id);
    expect($employee->company_id)->toBe($this->company->id);
    expect($employee->employee_code)->toBe('E-1234');
    expect($employee->status)->toBe(EmployeeStatus::Active);
});

it('writes an audit row with non-null tenant_id and company_id on create', function (): void {
    // This is the test that actually proves H1b-pre's company_id capture
    // works against a real production-shaped model (BelongsToTenant +
    // BelongsToCompany + Auditable). The trait fixtures cover the unit case;
    // this covers the realistic case.
    $employee = app(CreateEmployeeAction::class)->execute([
        'employee_code' => 'E-AUDIT',
        'full_name' => 'Audit Subject',
        'email' => null,
        'hire_date' => '2025-06-01',
        'status' => EmployeeStatus::Active->value,
    ]);

    $row = AuditLog::query()
        ->where('auditable_type', Employee::class)
        ->where('auditable_id', $employee->id)
        ->where('action', 'created')
        ->first();

    expect($row)->not->toBeNull();
    expect($row->tenant_id)->toBe($this->tenant->id);
    expect($row->company_id)->toBe($this->company->id);
});

it('rolls back the transaction on a unique-violation — no partial state, no audit row', function (): void {
    // Seed the conflict.
    app(CreateEmployeeAction::class)->execute([
        'employee_code' => 'DUP-001',
        'full_name' => 'First',
        'email' => null,
        'hire_date' => '2025-01-01',
        'status' => EmployeeStatus::Active->value,
    ]);

    $countBefore = Employee::query()->count();
    $auditBefore = AuditLog::query()->where('auditable_type', Employee::class)->count();

    // FormRequest validation guards code uniqueness at the HTTP boundary,
    // but the Action must STILL be safe against direct misuse (seeders,
    // future internal callers). Unique index in PG rejects the dupe.
    try {
        app(CreateEmployeeAction::class)->execute([
            'employee_code' => 'DUP-001',
            'full_name' => 'Second',
            'email' => null,
            'hire_date' => '2025-01-02',
            'status' => EmployeeStatus::Active->value,
        ]);
        $this->fail('Expected unique-violation to throw.');
    } catch (Throwable) {
        // Expected.
    }

    expect(Employee::query()->count())->toBe($countBefore);
    expect(AuditLog::query()->where('auditable_type', Employee::class)->count())->toBe($auditBefore);
});

it('captures the authenticated user as actor_id in the audit row', function (): void {
    $user = User::factory()->forTenant($this->tenant)->create();
    $this->actingAs($user);

    $employee = app(CreateEmployeeAction::class)->execute([
        'employee_code' => 'E-ACTOR',
        'full_name' => 'Has Actor',
        'email' => null,
        'hire_date' => '2025-03-01',
        'status' => EmployeeStatus::Active->value,
    ]);

    $row = AuditLog::query()
        ->where('auditable_type', Employee::class)
        ->where('auditable_id', $employee->id)
        ->first();

    expect($row->actor_id)->toBe($user->id);
});
