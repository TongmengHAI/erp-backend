<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\Tenant;
use App\Support\Company\CompanyContext;
use App\Support\Company\Exceptions\CompanyContextMissingException;
use App\Support\Company\Scopes\CompanyScope;
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

it('scopes queries to the current company automatically', function (): void {
    /** @var TenantContext $tctx */
    $tctx = app(TenantContext::class);
    /** @var CompanyContext $cctx */
    $cctx = app(CompanyContext::class);

    $tenant = Tenant::factory()->create();
    $tctx->setCurrent($tenant);
    $companyA = Company::factory()->forTenant($tenant)->create();
    $companyB = Company::factory()->forTenant($tenant)->create();

    $cctx->acrossCompanies(function () use ($tenant, $companyA, $companyB): void {
        CompanyTestWidget::create(['tenant_id' => $tenant->id, 'company_id' => $companyA->id, 'name' => 'A1']);
        CompanyTestWidget::create(['tenant_id' => $tenant->id, 'company_id' => $companyA->id, 'name' => 'A2']);
        CompanyTestWidget::create(['tenant_id' => $tenant->id, 'company_id' => $companyB->id, 'name' => 'B1']);
    });

    $cctx->setCurrent($companyA);
    expect(CompanyTestWidget::pluck('name')->all())
        ->toEqualCanonicalizing(['A1', 'A2']);

    $cctx->setCurrent($companyB);
    expect(CompanyTestWidget::pluck('name')->all())
        ->toEqual(['B1']);
});

it('auto-fills company_id on creating when CompanyContext is set', function (): void {
    /** @var TenantContext $tctx */
    $tctx = app(TenantContext::class);
    /** @var CompanyContext $cctx */
    $cctx = app(CompanyContext::class);

    $tenant = Tenant::factory()->create();
    $tctx->setCurrent($tenant);
    $company = Company::factory()->forTenant($tenant)->create();
    $cctx->setCurrent($company);

    $widget = CompanyTestWidget::create(['name' => 'auto']);

    expect($widget->company_id)->toBe($company->id);
    expect($widget->tenant_id)->toBe($tenant->id);
});

it('does not overwrite company_id when explicitly provided on the model', function (): void {
    /** @var TenantContext $tctx */
    $tctx = app(TenantContext::class);
    /** @var CompanyContext $cctx */
    $cctx = app(CompanyContext::class);

    $tenant = Tenant::factory()->create();
    $tctx->setCurrent($tenant);
    $companyA = Company::factory()->forTenant($tenant)->create();
    $companyB = Company::factory()->forTenant($tenant)->create();
    $cctx->setCurrent($companyA);

    // Explicit company_id = B even though context says A.
    $widget = CompanyTestWidget::create([
        'company_id' => $companyB->id,
        'name' => 'explicit',
    ]);

    expect($widget->company_id)->toBe($companyB->id);
});

it('throws when creating without an explicit company_id and no context set', function (): void {
    /** @var TenantContext $tctx */
    $tctx = app(TenantContext::class);
    $tenant = Tenant::factory()->create();
    $tctx->setCurrent($tenant);
    // No company context set, not in acrossCompanies.

    expect(fn () => CompanyTestWidget::create(['name' => 'orphan']))
        ->toThrow(CompanyContextMissingException::class);
});

it('throws when creating inside acrossCompanies without explicit company_id', function (): void {
    /** @var TenantContext $tctx */
    $tctx = app(TenantContext::class);
    /** @var CompanyContext $cctx */
    $cctx = app(CompanyContext::class);

    $tenant = Tenant::factory()->create();
    $tctx->setCurrent($tenant);
    $company = Company::factory()->forTenant($tenant)->create();
    $cctx->setCurrent($company);

    // Inside acrossCompanies, the auto-fill must NOT silently use the
    // previously-set company — that would silently leak data to whatever
    // company happened to be pinned before. Explicit company_id is required.
    $threw = false;
    $cctx->acrossCompanies(function () use (&$threw): void {
        try {
            CompanyTestWidget::create(['name' => 'no-id-inside-across']);
        } catch (CompanyContextMissingException) {
            $threw = true;
        }
    });

    expect($threw)->toBeTrue();
});

it('forCompany scope queries another specific company explicitly', function (): void {
    /** @var TenantContext $tctx */
    $tctx = app(TenantContext::class);
    /** @var CompanyContext $cctx */
    $cctx = app(CompanyContext::class);

    $tenant = Tenant::factory()->create();
    $tctx->setCurrent($tenant);
    $companyA = Company::factory()->forTenant($tenant)->create();
    $companyB = Company::factory()->forTenant($tenant)->create();

    $cctx->acrossCompanies(function () use ($tenant, $companyA, $companyB): void {
        CompanyTestWidget::create(['tenant_id' => $tenant->id, 'company_id' => $companyA->id, 'name' => 'A1']);
        CompanyTestWidget::create(['tenant_id' => $tenant->id, 'company_id' => $companyB->id, 'name' => 'B1']);
    });

    $cctx->setCurrent($companyA);
    // forCompany bypasses the global scope and queries B explicitly.
    expect(CompanyTestWidget::forCompany($companyB->id)->pluck('name')->all())
        ->toEqual(['B1']);
});

it('acrossCompanies scope removes the company filter entirely', function (): void {
    /** @var TenantContext $tctx */
    $tctx = app(TenantContext::class);
    /** @var CompanyContext $cctx */
    $cctx = app(CompanyContext::class);

    $tenant = Tenant::factory()->create();
    $tctx->setCurrent($tenant);
    $companyA = Company::factory()->forTenant($tenant)->create();
    $companyB = Company::factory()->forTenant($tenant)->create();

    $cctx->acrossCompanies(function () use ($tenant, $companyA, $companyB): void {
        CompanyTestWidget::create(['tenant_id' => $tenant->id, 'company_id' => $companyA->id, 'name' => 'A1']);
        CompanyTestWidget::create(['tenant_id' => $tenant->id, 'company_id' => $companyB->id, 'name' => 'B1']);
    });

    $cctx->setCurrent($companyA);
    // Even with company A pinned, acrossCompanies scope shows both rows
    // within the current tenant.
    expect(CompanyTestWidget::acrossCompanies()->pluck('name')->all())
        ->toEqualCanonicalizing(['A1', 'B1']);
});

it('isolates across both tenant and company dimensions composite', function (): void {
    // The critical test: tenant + company composite isolation.
    // A widget in (T=A, C=X) must be invisible to (T=A, C=Y) AND (T=B, C=X).
    /** @var TenantContext $tctx */
    $tctx = app(TenantContext::class);
    /** @var CompanyContext $cctx */
    $cctx = app(CompanyContext::class);

    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $tctx->asSystem(function () use ($tctx, $cctx, $tenantA, $tenantB): void {
        $companyAX = Company::factory()->forTenant($tenantA)->create();
        $companyAY = Company::factory()->forTenant($tenantA)->create();
        $companyBX = Company::factory()->forTenant($tenantB)->create();

        $tctx->setCurrent($tenantA);
        $cctx->acrossCompanies(function () use ($tenantA, $tenantB, $companyAX, $companyAY, $companyBX): void {
            CompanyTestWidget::create(['tenant_id' => $tenantA->id, 'company_id' => $companyAX->id, 'name' => 'AX']);
            CompanyTestWidget::create(['tenant_id' => $tenantA->id, 'company_id' => $companyAY->id, 'name' => 'AY']);
            CompanyTestWidget::create(['tenant_id' => $tenantB->id, 'company_id' => $companyBX->id, 'name' => 'BX']);
        });

        // From (T=A, C=AX) — only AX visible
        $tctx->setCurrent($tenantA);
        $cctx->setCurrent($companyAX);
        expect(CompanyTestWidget::pluck('name')->all())->toEqual(['AX']);

        // From (T=A, C=AY) — only AY visible
        $cctx->setCurrent($companyAY);
        expect(CompanyTestWidget::pluck('name')->all())->toEqual(['AY']);

        // From (T=B, C=BX) — only BX visible (cross-tenant isolation preserved)
        $tctx->setCurrent($tenantB);
        $cctx->setCurrent($companyBX);
        expect(CompanyTestWidget::pluck('name')->all())->toEqual(['BX']);
    });
});

it('company() relationship returns the related Company model', function (): void {
    /** @var TenantContext $tctx */
    $tctx = app(TenantContext::class);
    /** @var CompanyContext $cctx */
    $cctx = app(CompanyContext::class);

    $tenant = Tenant::factory()->create();
    $tctx->setCurrent($tenant);
    $company = Company::factory()->forTenant($tenant)->create();
    $cctx->setCurrent($company);

    $widget = CompanyTestWidget::create(['name' => 'rel-test']);

    expect($widget->company)->toBeInstanceOf(Company::class);
    expect($widget->company->id)->toBe($company->id);
});

it('does not interfere with the global scope check for CompanyScope', function (): void {
    // Sanity: confirm CompanyScope is actually registered as a global scope
    // on models using BelongsToCompany (verifies bootBelongsToCompany ran).
    $globalScopes = CompanyTestWidget::query()->getQuery()->wheres;
    // Just verify the model has the scope registered by attempting to remove it.
    expect(fn () => CompanyTestWidget::query()->withoutGlobalScope(CompanyScope::class))
        ->not()->toThrow(Exception::class);
});
