<?php

declare(strict_types=1);

use App\Domain\HRM\Enums\EmployeeStatus;
use App\Domain\HRM\Models\HrmSettings;
use App\Models\Company;
use App\Models\Tenant;
use App\Support\Company\Events\CompanyCreated;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a default HrmSettings row when a Company is created', function (): void {
    $tenant = Tenant::factory()->create();
    $company = Company::factory()->forTenant($tenant)->create();

    // Read without the global tenant/company scopes — tests don't
    // resolve a CompanyContext, and the listener-created row is for
    // the company we just made (not a "current" company in this
    // test's runtime context).
    $settings = HrmSettings::query()
        ->withoutGlobalScopes()
        ->where('company_id', $company->id)
        ->first();

    expect($settings)->not->toBeNull();
    expect($settings->tenant_id)->toBe($tenant->id);
    expect($settings->auto_generate_employee_code)->toBeFalse();
    expect($settings->employee_code_prefix)->toBeNull();
    expect($settings->default_employee_status)->toBe(EmployeeStatus::Active);
});

it('LOAD-BEARING: each tenant + company combination produces its own settings row (isolation)', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $companyA = Company::factory()->forTenant($tenantA)->create();
    $companyB = Company::factory()->forTenant($tenantB)->create();

    // Each company has its own row. Reading without the tenant/company
    // global scope (acrossCompanies escape hatch) so we can assert from
    // the test side without resolving a CompanyContext.
    $countA = HrmSettings::query()
        ->withoutGlobalScopes()
        ->where('company_id', $companyA->id)
        ->count();
    $countB = HrmSettings::query()
        ->withoutGlobalScopes()
        ->where('company_id', $companyB->id)
        ->count();

    expect($countA)->toBe(1);
    expect($countB)->toBe(1);
});

it('is idempotent — re-firing the event for the same company does not duplicate rows', function (): void {
    $tenant = Tenant::factory()->create();
    $company = Company::factory()->forTenant($tenant)->create();

    expect(HrmSettings::query()->withoutGlobalScopes()->where('company_id', $company->id)->count())->toBe(1);

    // Simulate a double-fire (e.g. manual dispatch after the model
    // event also fires). The firstOrCreate in the listener is the
    // idempotency guarantee; the unique index is the backstop.
    CompanyCreated::dispatch($company);

    expect(HrmSettings::query()->withoutGlobalScopes()->where('company_id', $company->id)->count())->toBe(1);
});
