<?php

declare(strict_types=1);

use App\Domain\HRM\Actions\CreateBranchAction;
use App\Domain\HRM\Enums\BranchStatus;
use App\Domain\HRM\Models\Branch;
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

it('persists a branch with the auto-filled tenant_id and company_id from context', function (): void {
    $branch = app(CreateBranchAction::class)->execute([
        'code' => 'B-PNH-HQ',
        'name' => 'Phnom Penh HQ',
        'description' => 'Main headquarters.',
        'address' => 'Street 240',
        'city' => 'Phnom Penh',
        'country_code' => 'KH',
        'phone' => '+855 23 123 456',
        'status' => BranchStatus::Active->value,
    ]);

    expect($branch->id)->not->toBeNull();
    expect($branch->tenant_id)->toBe($this->tenant->id);
    expect($branch->company_id)->toBe($this->company->id);
    expect($branch->code)->toBe('B-PNH-HQ');
    expect($branch->city)->toBe('Phnom Penh');
    expect($branch->country_code)->toBe('KH');
    expect($branch->status)->toBe(BranchStatus::Active);
});

it('writes an audit row with non-null tenant_id and company_id on create', function (): void {
    $branch = app(CreateBranchAction::class)->execute([
        'code' => 'B-AUDIT',
        'name' => 'Audit Subject',
        'description' => null,
        'address' => null,
        'city' => null,
        'country_code' => null,
        'phone' => null,
        'status' => BranchStatus::Active->value,
    ]);

    $row = AuditLog::query()
        ->where('auditable_type', Branch::class)
        ->where('auditable_id', $branch->id)
        ->where('action', 'created')
        ->first();

    expect($row)->not->toBeNull();
    expect($row->tenant_id)->toBe($this->tenant->id);
    expect($row->company_id)->toBe($this->company->id);
});

it('captures the authenticated user as actor_id in the audit row', function (): void {
    $user = User::factory()->forTenant($this->tenant)->create();
    $this->actingAs($user);

    $branch = app(CreateBranchAction::class)->execute([
        'code' => 'B-ACTOR',
        'name' => 'Has Actor',
        'description' => null,
        'address' => null,
        'city' => null,
        'country_code' => null,
        'phone' => null,
        'status' => BranchStatus::Active->value,
    ]);

    $row = AuditLog::query()
        ->where('auditable_type', Branch::class)
        ->where('auditable_id', $branch->id)
        ->first();

    expect($row->actor_id)->toBe($user->id);
});
