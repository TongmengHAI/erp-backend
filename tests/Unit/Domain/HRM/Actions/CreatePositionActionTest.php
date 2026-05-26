<?php

declare(strict_types=1);

use App\Domain\HRM\Actions\CreatePositionAction;
use App\Domain\HRM\Enums\PositionStatus;
use App\Domain\HRM\Models\Position;
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

it('persists a position with the auto-filled tenant_id and company_id from context', function (): void {
    $position = app(CreatePositionAction::class)->execute([
        'code' => 'P-MGR',
        'title' => 'Operations Manager',
        'description' => 'Heads day-to-day operations.',
        'status' => PositionStatus::Active->value,
    ]);

    expect($position->id)->not->toBeNull();
    expect($position->tenant_id)->toBe($this->tenant->id);
    expect($position->company_id)->toBe($this->company->id);
    expect($position->code)->toBe('P-MGR');
    expect($position->title)->toBe('Operations Manager');
    expect($position->status)->toBe(PositionStatus::Active);
});

it('writes an audit row with non-null tenant_id and company_id on create', function (): void {
    $position = app(CreatePositionAction::class)->execute([
        'code' => 'P-AUDIT',
        'title' => 'Audit Subject',
        'description' => null,
        'status' => PositionStatus::Active->value,
    ]);

    $row = AuditLog::query()
        ->where('auditable_type', Position::class)
        ->where('auditable_id', $position->id)
        ->where('action', 'created')
        ->first();

    expect($row)->not->toBeNull();
    expect($row->tenant_id)->toBe($this->tenant->id);
    expect($row->company_id)->toBe($this->company->id);
});

it('captures the authenticated user as actor_id in the audit row', function (): void {
    $user = User::factory()->forTenant($this->tenant)->create();
    $this->actingAs($user);

    $position = app(CreatePositionAction::class)->execute([
        'code' => 'P-ACTOR',
        'title' => 'Has Actor',
        'description' => null,
        'status' => PositionStatus::Active->value,
    ]);

    $row = AuditLog::query()
        ->where('auditable_type', Position::class)
        ->where('auditable_id', $position->id)
        ->first();

    expect($row->actor_id)->toBe($user->id);
});
