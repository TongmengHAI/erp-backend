<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Support\Tenancy\Exceptions\TenantContextMissingException;
use App\Support\Tenancy\Scopes\TenantScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\TenancyTestWidget;

beforeEach(function (): void {
    Schema::create('tenancy_test_widgets', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('tenant_id')->nullable()->index();
        $table->string('name');
        $table->timestampsTz();
    });
});

afterEach(function (): void {
    Schema::dropIfExists('tenancy_test_widgets');
});

it('scopes queries to the current tenant automatically', function (): void {
    /** @var TenantContext $ctx */
    $ctx = app(TenantContext::class);
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $ctx->asSystem(function () use ($tenantA, $tenantB): void {
        TenancyTestWidget::create(['tenant_id' => $tenantA->id, 'name' => 'A1']);
        TenancyTestWidget::create(['tenant_id' => $tenantA->id, 'name' => 'A2']);
        TenancyTestWidget::create(['tenant_id' => $tenantB->id, 'name' => 'B1']);
    });

    $ctx->setCurrent($tenantA);
    expect(TenancyTestWidget::pluck('name')->all())
        ->toEqualCanonicalizing(['A1', 'A2']);

    $ctx->setCurrent($tenantB);
    expect(TenancyTestWidget::pluck('name')->all())
        ->toEqual(['B1']);
});

it('auto-fills tenant_id on creating when TenantContext is set', function (): void {
    /** @var TenantContext $ctx */
    $ctx = app(TenantContext::class);
    $tenant = Tenant::factory()->create();
    $ctx->setCurrent($tenant);

    $widget = TenancyTestWidget::create(['name' => 'auto']);

    expect($widget->tenant_id)->toBe($tenant->id);
});

it('does not overwrite tenant_id if it was already set on the model', function (): void {
    /** @var TenantContext $ctx */
    $ctx = app(TenantContext::class);
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $ctx->setCurrent($tenantA);

    $widget = $ctx->asSystem(fn () => TenancyTestWidget::create([
        'tenant_id' => $tenantB->id,
        'name' => 'explicit',
    ]));

    expect($widget->tenant_id)->toBe($tenantB->id);
});

it('throws TenantContextMissingException when querying without a tenant set', function (): void {
    expect(fn () => TenancyTestWidget::all())
        ->toThrow(TenantContextMissingException::class);
});

it('throws TenantContextMissingException when creating without a tenant and without explicit tenant_id', function (): void {
    expect(fn () => TenancyTestWidget::create(['name' => 'no-tenant']))
        ->toThrow(TenantContextMissingException::class);
});

it('allows querying without scope inside TenantContext::asSystem', function (): void {
    /** @var TenantContext $ctx */
    $ctx = app(TenantContext::class);
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $ctx->asSystem(function () use ($tenantA, $tenantB): void {
        TenancyTestWidget::create(['tenant_id' => $tenantA->id, 'name' => 'A']);
        TenancyTestWidget::create(['tenant_id' => $tenantB->id, 'name' => 'B']);
    });

    $rows = $ctx->asSystem(fn () => TenancyTestWidget::pluck('name')->all());

    expect($rows)->toEqualCanonicalizing(['A', 'B']);
});

it('respects an explicit withoutGlobalScope(TenantScope::class)', function (): void {
    /** @var TenantContext $ctx */
    $ctx = app(TenantContext::class);
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $ctx->asSystem(function () use ($tenantA, $tenantB): void {
        TenancyTestWidget::create(['tenant_id' => $tenantA->id, 'name' => 'A']);
        TenancyTestWidget::create(['tenant_id' => $tenantB->id, 'name' => 'B']);
    });

    $ctx->setCurrent($tenantA);

    $all = TenancyTestWidget::withoutGlobalScope(TenantScope::class)
        ->pluck('name')
        ->all();

    expect($all)->toEqualCanonicalizing(['A', 'B']);
});

it('exposes a forTenant() scope that bypasses the global scope for a specific tenant', function (): void {
    /** @var TenantContext $ctx */
    $ctx = app(TenantContext::class);
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $ctx->asSystem(function () use ($tenantA, $tenantB): void {
        TenancyTestWidget::create(['tenant_id' => $tenantA->id, 'name' => 'A']);
        TenancyTestWidget::create(['tenant_id' => $tenantB->id, 'name' => 'B']);
    });

    $ctx->setCurrent($tenantA);
    $bRows = TenancyTestWidget::forTenant($tenantB->id)->pluck('name')->all();

    expect($bRows)->toEqual(['B']);
});

it('exposes a tenant() relation back to the Tenant model', function (): void {
    /** @var TenantContext $ctx */
    $ctx = app(TenantContext::class);
    $tenant = Tenant::factory()->create();
    $ctx->setCurrent($tenant);

    $widget = TenancyTestWidget::create(['name' => 'related']);

    expect($widget->tenant)->toBeInstanceOf(Tenant::class);
    expect($widget->tenant->id)->toBe($tenant->id);
});
