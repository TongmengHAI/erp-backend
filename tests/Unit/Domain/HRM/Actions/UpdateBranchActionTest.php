<?php

declare(strict_types=1);

use App\Domain\HRM\Actions\UpdateBranchAction;
use App\Domain\HRM\Enums\BranchStatus;
use App\Domain\HRM\Models\Branch;
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

    $this->branch = Branch::factory()->forCompany($this->company)->create([
        'name' => 'Original Branch',
        'status' => BranchStatus::Active,
    ]);
});

it('updates only the supplied fields and leaves the rest untouched', function (): void {
    $originalCode = $this->branch->code;

    $updated = app(UpdateBranchAction::class)
        ->execute($this->branch, ['name' => 'Renamed Branch']);

    expect($updated->name)->toBe('Renamed Branch');
    expect($updated->code)->toBe($originalCode);
});

it('writes a diff-only audit row capturing just the changed fields', function (): void {
    app(UpdateBranchAction::class)
        ->execute($this->branch, ['status' => BranchStatus::Archived->value]);

    $row = AuditLog::query()
        ->where('auditable_type', Branch::class)
        ->where('auditable_id', $this->branch->id)
        ->where('action', 'updated')
        ->latest('id')
        ->first();

    expect($row)->not->toBeNull();
    expect($row->before)->toEqual(['status' => 'active']);
    expect($row->after)->toEqual(['status' => 'archived']);
});
