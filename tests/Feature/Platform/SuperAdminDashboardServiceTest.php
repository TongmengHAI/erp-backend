<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────────
// SuperAdminDashboardServiceTest — metric correctness per Q6.
//
// Each test seeds a known-count fixture and asserts the metric returns
// exactly the expected count. The discipline matters because dashboard
// drift (off-by-one suspensions; recent-windows counted wrong) is the
// kind of bug that hides until a customer comments "that number looks
// wrong" — by which time we don't know which side is wrong.
// ─────────────────────────────────────────────────────────────────────────────

use App\Domain\Platform\Models\TenantModule;
use App\Domain\Platform\Services\SuperAdminDashboardService;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// ─── tile #1 + #2: tenantStatusCounts ───────────────────────────────────────

it('tenantStatusCounts: returns zero for every status when no tenants exist', function (): void {
    $svc = app(SuperAdminDashboardService::class);
    expect($svc->tenantStatusCounts())->toBe([
        'total' => 0,
        'active' => 0,
        'suspended' => 0,
        'archived' => 0,
    ]);
});

it('tenantStatusCounts: aggregates correctly across active + suspended + archived tenants', function (): void {
    Tenant::factory()->count(5)->withoutEntitlement()->create();
    Tenant::factory()->count(2)->suspended()->withoutEntitlement()->create();
    Tenant::factory()->count(1)->archived()->withoutEntitlement()->create();

    expect(app(SuperAdminDashboardService::class)->tenantStatusCounts())->toBe([
        'total' => 8,
        'active' => 5,
        'suspended' => 2,
        'archived' => 1,
    ]);
});

it('tenantStatusCounts: ignores soft-deleted tenants', function (): void {
    Tenant::factory()->count(3)->withoutEntitlement()->create();
    $deleted = Tenant::factory()->withoutEntitlement()->create();
    $deleted->delete();

    // SoftDeletes excludes the trashed row from the default query;
    // total should be 3 (not 4).
    expect(app(SuperAdminDashboardService::class)->tenantStatusCounts()['total'])->toBe(3);
});

// ─── tile #3: tenantsByModule ───────────────────────────────────────────────

it('tenantsByModule: pivots active + disabled counts per module key', function (): void {
    // 4 tenants with HRM active, 1 with HRM disabled.
    $active = Tenant::factory()->count(4)->create();
    $disabled = Tenant::factory()->withoutEntitlement()->create();
    TenantModule::factory()->forTenant($disabled)->disabled()->create();

    /** @var list<array{module_key: string, active_count: int, disabled_count: int}> $result */
    $result = app(SuperAdminDashboardService::class)->tenantsByModule();

    expect($result)->toHaveCount(1);
    expect($result[0]['module_key'])->toBe('hrm');
    expect($result[0]['active_count'])->toBe(4);
    expect($result[0]['disabled_count'])->toBe(1);
});

it('tenantsByModule: returns empty list when no entitlement rows exist', function (): void {
    Tenant::factory()->count(2)->withoutEntitlement()->create();

    expect(app(SuperAdminDashboardService::class)->tenantsByModule())->toBe([]);
});

it('tenantsByModule: excludes soft-deleted tenant_modules rows', function (): void {
    $tenant = Tenant::factory()->create();
    // Soft-delete the auto-created Active HRM row from the factory hook.
    TenantModule::query()
        ->acrossTenants()
        ->where('tenant_id', $tenant->id)
        ->delete();

    expect(app(SuperAdminDashboardService::class)->tenantsByModule())->toBe([]);
});

// ─── tile #4: recentSignups ─────────────────────────────────────────────────

it('recentSignups: returns tenants created within the last 7 days, ordered by created_at DESC', function (): void {
    // Two recent (inside window), one old (outside window).
    $recent1 = Tenant::factory()->withoutEntitlement()->create([
        'created_at' => Carbon::now()->subDays(1),
    ]);
    $recent2 = Tenant::factory()->withoutEntitlement()->create([
        'created_at' => Carbon::now()->subDays(3),
    ]);
    Tenant::factory()->withoutEntitlement()->create([
        'created_at' => Carbon::now()->subDays(30),
    ]);

    /** @var Collection<int, Tenant> $signups */
    $signups = app(SuperAdminDashboardService::class)->recentSignups();

    expect($signups->count())->toBe(2);
    // Ordered by created_at DESC — most recent first.
    expect($signups->first()->id)->toBe($recent1->id);
    expect($signups->last()->id)->toBe($recent2->id);
});

it('recentSignups: enforces the top-10 cap (older newer-than-7-days rows still count toward limit)', function (): void {
    // 12 tenants all inside the window. The cap is 10.
    foreach (range(1, 12) as $i) {
        Tenant::factory()->withoutEntitlement()->create([
            'created_at' => Carbon::now()->subHours($i),
        ]);
    }

    expect(app(SuperAdminDashboardService::class)->recentSignups()->count())->toBe(10);
});

// ─── tile #5: recentSuspensions ─────────────────────────────────────────────

it('recentSuspensions: returns suspended tenants updated within the last 7 days', function (): void {
    // One suspended-recently (in window), one suspended-long-ago (out
    // of window — DB stamp manually old). Plus a suspended tenant
    // updated >7 days ago.
    $recent = Tenant::factory()->suspended()->withoutEntitlement()->create();
    $old = Tenant::factory()->suspended()->withoutEntitlement()->create();
    DB::table('tenants')->where('id', $old->id)->update([
        'updated_at' => Carbon::now()->subDays(30),
    ]);
    // Active tenant in window — should be ignored.
    Tenant::factory()->withoutEntitlement()->create();

    /** @var Collection<int, Tenant> $suspensions */
    $suspensions = app(SuperAdminDashboardService::class)->recentSuspensions();

    expect($suspensions->count())->toBe(1);
    expect($suspensions->first()->id)->toBe($recent->id);
});

it('recentSuspensions: returns empty when no suspended tenants exist', function (): void {
    Tenant::factory()->count(3)->withoutEntitlement()->create();

    expect(app(SuperAdminDashboardService::class)->recentSuspensions()->count())->toBe(0);
});

// ─── invariant ─────────────────────────────────────────────────────────────

it('all service methods are callable without TenantContext — dashboard is platform-level', function (): void {
    // The dashboard is platform-level; no TenantContext is set when SA
    // hits /super-admin/dashboard. Every method must work without
    // tripping TenantScope. Use acrossTenants() / withoutGlobalScopes()
    // internally — proven here by the absence of any
    // TenantContextMissingException across all 5 reads.
    Tenant::factory()->count(2)->create();

    $svc = app(SuperAdminDashboardService::class);

    // No try/catch — if any throws, the test fails loudly.
    $svc->tenantStatusCounts();
    $svc->tenantsByModule();
    $svc->recentSignups();
    $svc->recentSuspensions();

    expect(true)->toBeTrue();
});
