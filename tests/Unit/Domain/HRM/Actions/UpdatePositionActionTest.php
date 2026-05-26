<?php

declare(strict_types=1);

use App\Domain\HRM\Actions\UpdatePositionAction;
use App\Domain\HRM\Enums\PositionStatus;
use App\Domain\HRM\Models\Position;
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

    $this->position = Position::factory()->forCompany($this->company)->create([
        'title' => 'Junior Clerk',
        'status' => PositionStatus::Active,
    ]);
});

it('updates only the supplied fields and leaves the rest untouched', function (): void {
    $originalCode = $this->position->code;

    $updated = app(UpdatePositionAction::class)
        ->execute($this->position, ['title' => 'Senior Clerk']);

    expect($updated->title)->toBe('Senior Clerk');
    expect($updated->code)->toBe($originalCode);
});

it('writes a diff-only audit row capturing just the changed fields', function (): void {
    app(UpdatePositionAction::class)
        ->execute($this->position, ['status' => PositionStatus::Archived->value]);

    $row = AuditLog::query()
        ->where('auditable_type', Position::class)
        ->where('auditable_id', $this->position->id)
        ->where('action', 'updated')
        ->latest('id')
        ->first();

    expect($row)->not->toBeNull();
    expect($row->before)->toEqual(['status' => 'active']);
    expect($row->after)->toEqual(['status' => 'archived']);
});
