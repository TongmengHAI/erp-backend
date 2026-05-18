<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────────
// AuditableCompanyTest — covers H1b-pre: audit_logs.company_id capture.
//
// Verifies that AuditWriter records the model's company_id on every audit row,
// preserving the null semantic for tenant-only identity models (User, Tenant,
// Company itself) and for rows written from acrossCompanies contexts where
// the model still carries its own company_id.
//
// Separate from AuditableTest so the company-scoped fixture and tenant-only
// fixture coexist without entangling each other's beforeEach schemas.
// ─────────────────────────────────────────────────────────────────────────────

use App\Models\Company;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Audit\Models\AuditLog;
use App\Support\Company\CompanyContext;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\AuditCompanyTestWidget;

beforeEach(function (): void {
    Schema::create('audit_company_test_widgets', function (Blueprint $t): void {
        $t->id();
        $t->unsignedBigInteger('tenant_id')->nullable()->index();
        $t->unsignedBigInteger('company_id')->nullable()->index();
        $t->string('name');
        $t->timestampsTz();
    });
});

afterEach(function (): void {
    Schema::dropIfExists('audit_company_test_widgets');
});

it('captures company_id on the audit row when a company-scoped model is created', function (): void {
    /** @var TenantContext $tctx */
    $tctx = app(TenantContext::class);
    /** @var CompanyContext $cctx */
    $cctx = app(CompanyContext::class);

    $tenant = Tenant::factory()->create();
    $tctx->setCurrent($tenant);
    $company = Company::factory()->forTenant($tenant)->create();
    $cctx->setCurrent($company);

    AuditCompanyTestWidget::create(['name' => 'scoped']);

    $row = AuditLog::query()
        ->where('auditable_type', AuditCompanyTestWidget::class)
        ->where('action', 'created')
        ->latest('id')
        ->first();

    expect($row)->not->toBeNull();
    expect($row->tenant_id)->toBe($tenant->id);
    expect($row->company_id)->toBe($company->id);
});

it('records company_id=null for a tenant-only audited model (Company itself)', function (): void {
    // Company is an identity-source model (BelongsToTenant, NOT BelongsToCompany).
    // Its audit rows must record company_id=null because the model has no
    // company dimension — even if a CompanyContext happens to be pinned.
    /** @var TenantContext $tctx */
    $tctx = app(TenantContext::class);
    /** @var CompanyContext $cctx */
    $cctx = app(CompanyContext::class);

    $tenant = Tenant::factory()->create();
    $tctx->setCurrent($tenant);
    $contextCompany = Company::factory()->forTenant($tenant)->create();
    $cctx->setCurrent($contextCompany);

    // Now create a SECOND company. The CompanyContext is set to $contextCompany,
    // but the audit row for this new Company creation must NOT inherit it.
    $newCompany = Company::factory()->forTenant($tenant)->create();

    $row = AuditLog::query()
        ->where('auditable_type', Company::class)
        ->where('auditable_id', $newCompany->id)
        ->where('action', 'created')
        ->first();

    expect($row)->not->toBeNull();
    expect($row->tenant_id)->toBe($tenant->id);
    expect($row->company_id)->toBeNull();
});

it('records company_id=null when a User is created (identity-source model, no company dimension)', function (): void {
    // User is the canonical tenant-only identity model. Even when a
    // CompanyContext is set on the request, user audit rows are company-free.
    /** @var TenantContext $tctx */
    $tctx = app(TenantContext::class);
    /** @var CompanyContext $cctx */
    $cctx = app(CompanyContext::class);

    $tenant = Tenant::factory()->create();
    $tctx->setCurrent($tenant);
    $contextCompany = Company::factory()->forTenant($tenant)->create();
    $cctx->setCurrent($contextCompany);

    $user = User::factory()->forTenant($tenant)->create();

    $row = AuditLog::query()
        ->where('auditable_type', User::class)
        ->where('auditable_id', $user->id)
        ->where('action', 'created')
        ->first();

    expect($row)->not->toBeNull();
    expect($row->tenant_id)->toBe($tenant->id);
    expect($row->company_id)->toBeNull();
});

it('still captures the model own company_id inside acrossCompanies — context is bypassed but the attribute is not', function (): void {
    // acrossCompanies clears the CompanyScope and disables auto-fill, but if
    // the caller passes an explicit company_id on the model, that value must
    // still flow into the audit row. The model attribute is authoritative —
    // not the context.
    /** @var TenantContext $tctx */
    $tctx = app(TenantContext::class);
    /** @var CompanyContext $cctx */
    $cctx = app(CompanyContext::class);

    $tenant = Tenant::factory()->create();
    $tctx->setCurrent($tenant);
    $companyA = Company::factory()->forTenant($tenant)->create();
    $companyB = Company::factory()->forTenant($tenant)->create();

    $widget = $cctx->acrossCompanies(function () use ($tenant, $companyB) {
        return AuditCompanyTestWidget::create([
            'tenant_id' => $tenant->id,
            'company_id' => $companyB->id,
            'name' => 'cross-co-explicit',
        ]);
    });

    $row = AuditLog::query()
        ->where('auditable_type', AuditCompanyTestWidget::class)
        ->where('auditable_id', $widget->id)
        ->where('action', 'created')
        ->first();

    expect($row)->not->toBeNull();
    expect($row->tenant_id)->toBe($tenant->id);
    expect($row->company_id)->toBe($companyB->id);
    expect($companyA->id)->not->toBe($companyB->id);
});

it('propagates the composite (tenant_id, company_id, auditable_type, auditable_id) index from parent to every child partition', function (): void {
    // Verifies the partition-propagation guarantee: declarative partitioning
    // means the index defined on the parent is auto-created on every existing
    // and future child partition. If this assertion fails, partitioning has
    // silently regressed to inheritance-style and the H1b-pre plan's
    // assumption breaks.
    //
    // Match by index definition (the column list) rather than by name — PG
    // auto-generates child index names as <partition>_<col1>_<col2>..._idx,
    // which truncates at the identifier-length limit and is fragile to assert
    // on textually.

    // (a) Parent table itself must be declaratively partitioned (relkind=p, RANGE).
    $parentInfo = DB::select(
        "SELECT c.relkind, pt.partstrat
         FROM pg_class c
         LEFT JOIN pg_partitioned_table pt ON pt.partrelid = c.oid
         WHERE c.relname = 'audit_logs'"
    );

    expect($parentInfo)->not->toBeEmpty();
    // relkind=p → declaratively-partitioned table.
    expect($parentInfo[0]->relkind)->toBe('p');
    // partstrat=r → RANGE partitioning.
    expect($parentInfo[0]->partstrat)->toBe('r');

    // (b) Count child partitions via pg_inherits (only declarative parents
    // have entries here for relkind=p tables).
    $childCount = (int) DB::select(
        "SELECT COUNT(*) AS n FROM pg_inherits WHERE inhparent::regclass::text = 'audit_logs'"
    )[0]->n;

    expect($childCount)->toBeGreaterThan(1);

    // (c) The composite index must exist on the parent AND on every child.
    /** @var array<int, object{indexname: string, tablename: string}> $indexes */
    $indexes = DB::select(
        "SELECT indexname, tablename
         FROM pg_indexes
         WHERE indexdef LIKE '%(tenant_id, company_id, auditable_type, auditable_id)%'
           AND (tablename = 'audit_logs' OR tablename LIKE 'audit_logs_%')"
    );

    $tables = array_map(static fn ($r): string => $r->tablename, $indexes);

    // Parent must have the composite index.
    expect($tables)->toContain('audit_logs');

    // Every child partition must have its own — if even one is missing,
    // partition propagation has silently regressed to inheritance-style.
    $childTablesWithIndex = array_values(array_filter(
        $tables,
        static fn (string $t): bool => $t !== 'audit_logs',
    ));

    expect(count($childTablesWithIndex))->toBe($childCount);
});
