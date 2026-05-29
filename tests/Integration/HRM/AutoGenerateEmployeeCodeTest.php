<?php

declare(strict_types=1);

use App\Domain\HRM\Actions\CreateEmployeeAction;
use App\Domain\HRM\Enums\EmployeeStatus;
use App\Domain\HRM\Models\HrmEmployeeCodeSequence;
use App\Domain\HRM\Models\HrmSettings;
use App\Domain\HRM\Services\HrmSettingsRepository;
use App\Models\Company;
use App\Models\Tenant;
use App\Support\Company\CompanyContext;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    $this->company = Company::factory()->forTenant($this->tenant)->create();
    app(TenantContext::class)->setCurrent($this->tenant);
    app(CompanyContext::class)->setCurrent($this->company);

    // Enable auto-gen with prefix TT- for this test. The bootstrap
    // listener already created a default-state settings row when
    // Company was created; we flip the flag for the auto-gen path.
    HrmSettings::query()
        ->withoutGlobalScopes()
        ->where('company_id', $this->company->id)
        ->update([
            'auto_generate_employee_code' => true,
            'employee_code_prefix' => 'TT-',
        ]);
});

// ─────────────────────────────────────────────────────────────────────────────
// LOAD-BEARING: this is the test from the slice plan that's listed by
// name. Two sequential creates → distinct codes (TT-1, TT-2) → sequence
// row's next_value lands at 3.
//
// Concurrency safety (two SIMULTANEOUS creates produce distinct codes)
// depends on SELECT FOR UPDATE inside DB::transaction. Pest can't
// trivially drive concurrent connections; the discipline is documented
// in EmployeeCodeGenerator's class docblock and the lockForUpdate +
// transaction is visible in the code path. Real concurrency
// verification is a load-test activity (post-v1).
// ─────────────────────────────────────────────────────────────────────────────

it('LOAD-BEARING: auto-gen produces sequential distinct codes (TT-1, TT-2) and increments the sequence row', function (): void {
    $action = app(CreateEmployeeAction::class);

    // Sequence row doesn't exist yet — lazy init on first auto-gen use.
    expect(
        HrmEmployeeCodeSequence::query()
            ->withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->count()
    )->toBe(0);

    // Create employee #1.
    $emp1 = $action->execute([
        'full_name' => 'First Employee',
        'hire_date' => '2026-06-01',
        'status' => EmployeeStatus::Active->value,
    ]);
    expect($emp1->employee_code)->toBe('TT-1');

    // Create employee #2.
    $emp2 = $action->execute([
        'full_name' => 'Second Employee',
        'hire_date' => '2026-06-02',
        'status' => EmployeeStatus::Active->value,
    ]);
    expect($emp2->employee_code)->toBe('TT-2');

    // Sequence row now exists with next_value = 3 (next caller would
    // get TT-3 if they ran).
    $sequence = HrmEmployeeCodeSequence::query()
        ->withoutGlobalScopes()
        ->where('company_id', $this->company->id)
        ->first();
    expect($sequence)->not->toBeNull();
    expect($sequence->next_value)->toBe(3);
});

it('manual-mode (auto-gen OFF) continues to require employee_code from the caller', function (): void {
    HrmSettings::query()
        ->withoutGlobalScopes()
        ->where('company_id', $this->company->id)
        ->update(['auto_generate_employee_code' => false]);
    // Repository cache is per-request; the singleton already cached
    // the auto-gen=true row from beforeEach. Reset the cached repo
    // so the next action call re-reads.
    app()->forgetInstance(HrmSettingsRepository::class);

    $emp = app(CreateEmployeeAction::class)->execute([
        'employee_code' => 'MANUAL-001',
        'full_name' => 'Manual Mode',
        'hire_date' => '2026-06-03',
        'status' => EmployeeStatus::Active->value,
    ]);

    expect($emp->employee_code)->toBe('MANUAL-001');

    // Sequence row was NOT created — manual mode doesn't touch it.
    expect(
        HrmEmployeeCodeSequence::query()
            ->withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->count()
    )->toBe(0);
});

it('rejects the call when auto-gen is on AND the caller smuggles employee_code (defensive InvalidArgumentException)', function (): void {
    expect(fn () => app(CreateEmployeeAction::class)->execute([
        'employee_code' => 'SMUGGLED-1',
        'full_name' => 'Smuggler',
        'hire_date' => '2026-06-04',
        'status' => EmployeeStatus::Active->value,
    ]))->toThrow(InvalidArgumentException::class);
});

it('persists existing auto-generated codes when the prefix changes (Q6: stay-as-is)', function (): void {
    // Create one employee with prefix TT-.
    $emp1 = app(CreateEmployeeAction::class)->execute([
        'full_name' => 'Before Prefix Change',
        'hire_date' => '2026-06-01',
        'status' => EmployeeStatus::Active->value,
    ]);
    expect($emp1->employee_code)->toBe('TT-1');

    // Admin updates the prefix to ACME-.
    HrmSettings::query()
        ->withoutGlobalScopes()
        ->where('company_id', $this->company->id)
        ->update(['employee_code_prefix' => 'ACME-']);
    app()->forgetInstance(HrmSettingsRepository::class);

    // Existing employee #1's code is unchanged.
    $emp1->refresh();
    expect($emp1->employee_code)->toBe('TT-1');

    // Next employee gets the new prefix; counter continues (ACME-2,
    // not ACME-1 — the sequence persists across prefix changes per Q5).
    $emp2 = app(CreateEmployeeAction::class)->execute([
        'full_name' => 'After Prefix Change',
        'hire_date' => '2026-06-02',
        'status' => EmployeeStatus::Active->value,
    ]);
    expect($emp2->employee_code)->toBe('ACME-2');
});
