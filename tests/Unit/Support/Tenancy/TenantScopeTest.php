<?php

declare(strict_types=1);

use App\Models\Tenant;
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

it('appends a qualified WHERE tenant_id clause to the SQL', function (): void {
    /** @var TenantContext $ctx */
    $ctx = app(TenantContext::class);
    $tenant = Tenant::factory()->create();
    $ctx->setCurrent($tenant);

    $sql = TenancyTestWidget::query()->toSql();

    expect($sql)
        ->toContain('"tenancy_test_widgets"."tenant_id"')
        ->toContain('= ?');
});

it('emits no WHERE clause inside asSystem', function (): void {
    /** @var TenantContext $ctx */
    $ctx = app(TenantContext::class);

    $sql = $ctx->asSystem(fn () => TenancyTestWidget::query()->toSql());

    expect($sql)->not()->toContain('tenant_id');
});

it('correctly aliases tenant_id when joining another table', function (): void {
    /** @var TenantContext $ctx */
    $ctx = app(TenantContext::class);
    $tenant = Tenant::factory()->create();
    $ctx->setCurrent($tenant);

    $sql = TenancyTestWidget::query()
        ->join('tenants', 'tenants.id', '=', 'tenancy_test_widgets.tenant_id')
        ->toSql();

    // The scope must qualify tenant_id by table to avoid ambiguity with tenants.id.
    expect($sql)->toContain('"tenancy_test_widgets"."tenant_id"');
});
