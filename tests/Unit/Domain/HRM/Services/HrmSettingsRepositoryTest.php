<?php

declare(strict_types=1);

use App\Domain\HRM\Models\HrmSettings;
use App\Domain\HRM\Services\HrmSettingsRepository;
use App\Models\Company;
use App\Models\Tenant;
use App\Support\Company\CompanyContext;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    $this->company = Company::factory()->forTenant($this->tenant)->create();
    app(TenantContext::class)->setCurrent($this->tenant);
    app(CompanyContext::class)->setCurrent($this->company);
});

it('returns the settings row for the current company', function (): void {
    $settings = app(HrmSettingsRepository::class)->getForCurrentCompany();

    expect($settings)->toBeInstanceOf(HrmSettings::class);
    expect($settings->company_id)->toBe($this->company->id);
});

it('caches the result per request — second call does not hit the DB', function (): void {
    $repo = app(HrmSettingsRepository::class);

    // Warm the cache.
    $first = $repo->getForCurrentCompany();

    // Count queries during the second call.
    DB::enableQueryLog();
    DB::flushQueryLog();
    $second = $repo->getForCurrentCompany();
    $log = DB::getQueryLog();
    DB::disableQueryLog();

    expect(count($log))->toBe(0);
    // Same object instance (cached, not refetched).
    expect($second)->toBe($first);
});
