<?php

declare(strict_types=1);

use App\Domain\HRM\Actions\UpdateHrmSettingsAction;
use App\Domain\HRM\Enums\EmployeeStatus;
use App\Domain\HRM\Models\HrmSettings;
use App\Models\Company;
use App\Models\Tenant;
use App\Support\Audit\Models\AuditLog;
use App\Support\Company\CompanyContext;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    $this->company = Company::factory()->forTenant($this->tenant)->create();
    app(TenantContext::class)->setCurrent($this->tenant);
    app(CompanyContext::class)->setCurrent($this->company);

    // The bootstrap listener already created the row; grab it.
    $this->settings = HrmSettings::query()
        ->withoutGlobalScopes()
        ->where('company_id', $this->company->id)
        ->first();
});

it('updates auto_generate_employee_code and persists the change', function (): void {
    $updated = app(UpdateHrmSettingsAction::class)->execute($this->settings, [
        'auto_generate_employee_code' => true,
        'employee_code_prefix' => 'TT-',
    ]);

    expect($updated->auto_generate_employee_code)->toBeTrue();
    expect($updated->employee_code_prefix)->toBe('TT-');
});

it('writes a diff-only audit row capturing the changed fields', function (): void {
    app(UpdateHrmSettingsAction::class)->execute($this->settings, [
        'default_employee_status' => EmployeeStatus::OnLeave->value,
    ]);

    $row = AuditLog::query()
        ->where('auditable_type', HrmSettings::class)
        ->where('auditable_id', $this->settings->id)
        ->where('action', 'updated')
        ->latest('id')
        ->first();

    expect($row)->not->toBeNull();
    expect($row->before)->toHaveKey('default_employee_status');
    expect($row->after)->toHaveKey('default_employee_status');
    expect($row->after['default_employee_status'])->toBe('on_leave');
});
