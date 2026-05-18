<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\Tenant;
use App\Support\Company\CompanyContext;
use App\Support\Company\Exceptions\CompanyContextMissingException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\CompanyTestWidget;

beforeEach(function (): void {
    Schema::create('company_test_widgets', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('tenant_id')->nullable()->index();
        $table->unsignedBigInteger('company_id')->nullable()->index();
        $table->string('name');
        $table->timestampsTz();
    });
});

afterEach(function (): void {
    Schema::dropIfExists('company_test_widgets');
});

it('appends a qualified WHERE company_id clause to the SQL', function (): void {
    /** @var TenantContext $tctx */
    $tctx = app(TenantContext::class);
    /** @var CompanyContext $cctx */
    $cctx = app(CompanyContext::class);

    $tenant = Tenant::factory()->create();
    $tctx->setCurrent($tenant);
    $company = Company::factory()->forTenant($tenant)->create();
    $cctx->setCurrent($company);

    $sql = CompanyTestWidget::query()->toSql();

    expect($sql)
        ->toContain('"company_test_widgets"."company_id"')
        ->toContain('= ?');
});

it('emits no company WHERE clause inside acrossCompanies', function (): void {
    /** @var TenantContext $tctx */
    $tctx = app(TenantContext::class);
    /** @var CompanyContext $cctx */
    $cctx = app(CompanyContext::class);

    $tenant = Tenant::factory()->create();
    $tctx->setCurrent($tenant);

    $sql = $cctx->acrossCompanies(fn () => CompanyTestWidget::query()->toSql());

    expect($sql)->not()->toContain('company_id');
    // Tenant scope still applies — only company scope is bypassed.
    expect($sql)->toContain('tenant_id');
});

it('throws CompanyContextMissingException when no company is set and not in acrossCompanies', function (): void {
    /** @var TenantContext $tctx */
    $tctx = app(TenantContext::class);
    $tenant = Tenant::factory()->create();
    $tctx->setCurrent($tenant);
    // CompanyContext NOT set, NOT in acrossCompanies mode.

    expect(fn () => CompanyTestWidget::query()->toSql())
        ->toThrow(CompanyContextMissingException::class);
});

it('both tenant and company filters apply on a model using both traits', function (): void {
    /** @var TenantContext $tctx */
    $tctx = app(TenantContext::class);
    /** @var CompanyContext $cctx */
    $cctx = app(CompanyContext::class);

    $tenant = Tenant::factory()->create();
    $tctx->setCurrent($tenant);
    $company = Company::factory()->forTenant($tenant)->create();
    $cctx->setCurrent($company);

    $sql = CompanyTestWidget::query()->toSql();

    expect($sql)
        ->toContain('"company_test_widgets"."tenant_id"')
        ->toContain('"company_test_widgets"."company_id"');
});
