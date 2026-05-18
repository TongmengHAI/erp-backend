<?php

declare(strict_types=1);

use App\Domain\HRM\Actions\UpdateDepartmentAction;
use App\Domain\HRM\Enums\DepartmentStatus;
use App\Domain\HRM\Models\Department;
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

    $this->department = Department::factory()->forCompany($this->company)->create([
        'name' => 'Original Name',
        'description' => 'Original description.',
        'status' => DepartmentStatus::Active,
    ]);
});

it('updates only the supplied fields and leaves the rest untouched', function (): void {
    $originalCode = $this->department->code;

    $updated = app(UpdateDepartmentAction::class)
        ->execute($this->department, ['name' => 'Updated Name']);

    expect($updated->name)->toBe('Updated Name');
    expect($updated->description)->toBe('Original description.');
    expect($updated->code)->toBe($originalCode);
});

it('writes an audit row with a diff-only before/after of just the changed fields', function (): void {
    app(UpdateDepartmentAction::class)
        ->execute($this->department, ['status' => DepartmentStatus::Archived->value]);

    $row = AuditLog::query()
        ->where('auditable_type', Department::class)
        ->where('auditable_id', $this->department->id)
        ->where('action', 'updated')
        ->latest('id')
        ->first();

    expect($row)->not->toBeNull();
    expect($row->tenant_id)->toBe($this->tenant->id);
    expect($row->company_id)->toBe($this->company->id);
    // Diff-only: only `status` should appear in before/after, NOT untouched
    // fields like name or description.
    expect($row->before)->toEqual(['status' => 'active']);
    expect($row->after)->toEqual(['status' => 'archived']);
});

it('returns the refreshed model so casts (enum status) reflect the post-save state', function (): void {
    $updated = app(UpdateDepartmentAction::class)
        ->execute($this->department, [
            'status' => DepartmentStatus::Archived->value,
            'description' => 'Now archived.',
        ]);

    expect($updated->status)->toBe(DepartmentStatus::Archived);
    expect($updated->description)->toBe('Now archived.');
});
