<?php

declare(strict_types=1);

use App\Domain\HRM\Actions\CreateDepartmentAction;
use App\Domain\HRM\Enums\DepartmentStatus;
use App\Domain\HRM\Models\Department;
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

it('persists a department with the auto-filled tenant_id and company_id from context', function (): void {
    $department = app(CreateDepartmentAction::class)->execute([
        'code' => 'D-OPS',
        'name' => 'Operations',
        'description' => 'Day-to-day operations team.',
        'status' => DepartmentStatus::Active->value,
    ]);

    expect($department->id)->not->toBeNull();
    expect($department->tenant_id)->toBe($this->tenant->id);
    expect($department->company_id)->toBe($this->company->id);
    expect($department->code)->toBe('D-OPS');
    expect($department->status)->toBe(DepartmentStatus::Active);
});

it('writes an audit row with non-null tenant_id and company_id on create', function (): void {
    // Mirrors CreateEmployeeActionTest's audit assertion — proves H1b-pre's
    // company_id capture works for every BelongsToCompany model, not just
    // Employee. The trait fixtures cover the unit case; this covers
    // Department-as-realistic-shape.
    $department = app(CreateDepartmentAction::class)->execute([
        'code' => 'D-AUDIT',
        'name' => 'Audit Subject',
        'description' => null,
        'status' => DepartmentStatus::Active->value,
    ]);

    $row = AuditLog::query()
        ->where('auditable_type', Department::class)
        ->where('auditable_id', $department->id)
        ->where('action', 'created')
        ->first();

    expect($row)->not->toBeNull();
    expect($row->tenant_id)->toBe($this->tenant->id);
    expect($row->company_id)->toBe($this->company->id);
});

it('rolls back the transaction on a unique-violation — no partial state, no audit row', function (): void {
    // Seed the conflict.
    app(CreateDepartmentAction::class)->execute([
        'code' => 'DUP-001',
        'name' => 'First',
        'description' => null,
        'status' => DepartmentStatus::Active->value,
    ]);

    $countBefore = Department::query()->count();
    $auditBefore = AuditLog::query()->where('auditable_type', Department::class)->count();

    // FormRequest validation guards code uniqueness at the HTTP boundary,
    // but the Action must STILL be safe against direct misuse (seeders,
    // future internal callers). Unique index in PG rejects the dupe.
    try {
        app(CreateDepartmentAction::class)->execute([
            'code' => 'DUP-001',
            'name' => 'Second',
            'description' => null,
            'status' => DepartmentStatus::Active->value,
        ]);
        $this->fail('Expected unique-violation to throw.');
    } catch (Throwable) {
        // Expected.
    }

    expect(Department::query()->count())->toBe($countBefore);
    expect(AuditLog::query()->where('auditable_type', Department::class)->count())->toBe($auditBefore);
});

it('captures the authenticated user as actor_id in the audit row', function (): void {
    $user = User::factory()->forTenant($this->tenant)->create();
    $this->actingAs($user);

    $department = app(CreateDepartmentAction::class)->execute([
        'code' => 'D-ACTOR',
        'name' => 'Has Actor',
        'description' => null,
        'status' => DepartmentStatus::Active->value,
    ]);

    $row = AuditLog::query()
        ->where('auditable_type', Department::class)
        ->where('auditable_id', $department->id)
        ->first();

    expect($row->actor_id)->toBe($user->id);
});
