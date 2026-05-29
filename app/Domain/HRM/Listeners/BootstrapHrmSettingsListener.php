<?php

declare(strict_types=1);

namespace App\Domain\HRM\Listeners;

use App\Domain\HRM\Enums\EmployeeStatus;
use App\Domain\HRM\Models\HrmSettings;
use App\Support\Company\CompanyContext;
use App\Support\Company\Events\CompanyCreated;
use App\Support\Tenancy\TenantContext;

/**
 * Creates the default hrm_settings row for a newly-created Company.
 *
 * Synchronous (not queued) — the listener runs in the same request
 * that created the company, so any HRM read against the new company
 * within the same request finds a settings row. firstOrCreate guards
 * against double-firing (e.g. via Event::dispatch in a test on top
 * of the natural model event); the unique index on
 * (tenant_id, company_id) is the final backstop.
 *
 * Defaults (per slice plan Q1–Q5 + open-question locks):
 *   • auto_generate_employee_code = false  (admin opts in explicitly)
 *   • employee_code_prefix         = NULL  (no tenant-slug coupling)
 *   • default_employee_status      = active
 *
 * The hrm_employee_code_sequences row is NOT created here. Most
 * companies will never enable auto-gen; eager-creating a counter is
 * wasted work. EmployeeCodeGenerator's firstOrCreate handles
 * initialization on first auto-gen use.
 */
final class BootstrapHrmSettingsListener
{
    public function handle(CompanyCreated $event): void
    {
        // CompanyContext may not be resolved (the listener fires during
        // Company creation, before CompanyContext has been set for the
        // new company). HrmSettings's BelongsToCompany global scope
        // demands a context to filter reads — we need to bypass it for
        // this bootstrap query. Same pattern the TenantContext::asSystem
        // / CompanyContext::acrossCompanies escape hatch is built for.
        //
        // We're passing tenant_id + company_id explicitly in the
        // firstOrCreate attributes, so no auto-fill is needed; the
        // bypass is purely for the scoped READ during firstOrCreate's
        // existence check.
        $tenantContext = app(TenantContext::class);
        $companyContext = app(CompanyContext::class);

        $tenantContext->asSystem(function () use ($event, $companyContext): void {
            $companyContext->acrossCompanies(function () use ($event): void {
                HrmSettings::query()->firstOrCreate(
                    [
                        'tenant_id' => $event->company->tenant_id,
                        'company_id' => $event->company->id,
                    ],
                    [
                        'auto_generate_employee_code' => false,
                        'employee_code_prefix' => null,
                        'default_employee_status' => EmployeeStatus::Active->value,
                    ],
                );
            });
        });
    }
}
