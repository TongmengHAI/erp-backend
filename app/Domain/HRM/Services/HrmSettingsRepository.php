<?php

declare(strict_types=1);

namespace App\Domain\HRM\Services;

use App\Domain\HRM\Models\HrmSettings;
use App\Support\Company\CompanyContext;

/**
 * Per-request cache for the current company's HRM settings.
 *
 * Bound as `scoped` in AppServiceProvider — Laravel's scoped lifetime
 * is per HTTP request / per Artisan command / per queue job. The
 * in-memory cache lives for one of those and resets cleanly between
 * — no leaks across requests, no manual flush needed.
 *
 * Why a service rather than direct Eloquent reads at every callsite:
 *   • StoreEmployeeRequest::rules() reads settings to decide if
 *     employee_code is required vs prohibited.
 *   • CreateEmployeeAction::execute() reads settings to decide auto-gen
 *     vs manual path.
 *   • The same request that runs both calls would otherwise issue two
 *     DB queries for identical data. The repository caches once.
 *
 * Future bulk-create paths (CSV import etc.) would amplify the benefit
 * further — N employee creates in a single request, one settings read.
 *
 * Only one method (`getForCurrentCompany`). The admin page's show
 * controller does its own direct Eloquent fetch (it may be looking at
 * a different company's settings via the URL company_id picker), so
 * a `getForCompany(int)` variant isn't needed yet.
 */
final class HrmSettingsRepository
{
    private ?HrmSettings $cached = null;

    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {}

    /**
     * Resolve the HrmSettings row for the current request's company.
     * The row is guaranteed to exist for every company via the
     * BootstrapHrmSettingsListener + migration backfill; if it doesn't,
     * something has gone wrong upstream (company created without the
     * listener firing — e.g. raw DB insert), and a 500 is the right
     * surface ("system is in an inconsistent state").
     */
    public function getForCurrentCompany(): HrmSettings
    {
        return $this->cached ??= HrmSettings::query()
            ->where('company_id', $this->companyContext->currentId())
            ->firstOrFail();
    }
}
