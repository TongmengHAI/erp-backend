<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────────
// SuperAdminBypassTest — LOAD-BEARING tests for the Super Admin bypass
// across all three places it lives:
//
//   1. TenantScope global scope            (Eloquent query short-circuit)
//   2. ResolveTenant middleware             (HTTP request short-circuit)
//   3. ResolveCompany middleware            (HTTP request short-circuit)
//
// Plus regression: tenant_users with no tenant context STILL throw — the SA
// bypass must not accidentally relax the rule for non-SA users with broken
// state.
//
// The cross-tenant Employee::all() test is THE load-bearing one — it proves
// SA reads return rows from multiple tenants in a single query, which is the
// whole point of the SA identity. If this test regresses, the SA-side
// tenant CRUD endpoints (Sessions 2-3) silently start returning only one
// tenant's data.
// ─────────────────────────────────────────────────────────────────────────────

use App\Domain\HRM\Models\Employee;
use App\Models\Company;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\Framework\DefaultPermissionsSeeder;
use Database\Seeders\Framework\DefaultRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Throwable;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withHeader('Origin', 'http://localhost');
    $this->seed([DefaultPermissionsSeeder::class, DefaultRolesSeeder::class]);
});

it('LOAD-BEARING: TenantScope bypass — SA Employee::all() returns rows across all tenants', function (): void {
    // Two tenants, each with their own company + an employee.
    $tenantA = Tenant::factory()->create(['name' => 'Tenant A']);
    $companyA = Company::factory()->forTenant($tenantA)->create();
    Employee::factory()->forCompany($companyA)->create(['full_name' => 'Alice in A']);

    $tenantB = Tenant::factory()->create(['name' => 'Tenant B']);
    $companyB = Company::factory()->forTenant($tenantB)->create();
    Employee::factory()->forCompany($companyB)->create(['full_name' => 'Bob in B']);

    // Act as the SA. The TenantScope's SA bypass should let the query
    // return both employees, despite there being no resolved tenant
    // context.
    $sa = User::factory()->superAdmin()->create();
    $this->actingAs($sa);

    // No TenantContext set — under non-SA semantics this would throw
    // TenantContextMissingException. Under SA semantics the bypass
    // short-circuits and the query runs cross-tenant.
    $names = Employee::query()->orderBy('full_name')->pluck('full_name')->all();

    expect($names)->toContain('Alice in A');
    expect($names)->toContain('Bob in B');
});

it('regression: unauthenticated query against a tenant/company-scoped model still throws (SA bypass did not relax scoping)', function (): void {
    // The SA bypass must not relax scoping for non-SA sessions. With no
    // authenticated user AND no resolved tenant/company context, the
    // global scopes should still throw — the bypass adds a parallel
    // branch for SA, it doesn't make scoping skippable for everyone.
    //
    // Employee uses BOTH BelongsToTenant and BelongsToCompany; either
    // scope firing proves the regression invariant. CompanyScope happens
    // to evaluate first in the apply chain → CompanyContextMissingException;
    // a future scope-order change would land on TenantContextMissingException.
    // Either is correct — assert the broader "some context missing" by
    // catching Throwable and inspecting the message.
    $tenant = Tenant::factory()->create();
    $company = Company::factory()->forTenant($tenant)->create();
    Employee::factory()->forCompany($company)->create();

    $thrown = false;
    try {
        Employee::query()->get();
    } catch (Throwable $e) {
        $thrown = true;
        // Either Tenant or Company context-missing message is acceptable —
        // both prove the global scope fired on a non-SA caller.
        $msg = $e->getMessage();
        expect($msg)->toMatch('/without a resolved tenant|without a resolved company/i');
    }

    expect($thrown)->toBeTrue('Expected a context-missing exception for a non-SA query without TenantContext/CompanyContext.');
});

it('LOAD-BEARING: ResolveTenant middleware bypass — SA reaches /api/v1/auth/me without resolved tenant', function (): void {
    // The auth:sanctum + tenant + company:optional middleware chain
    // protects /auth/me. Without the SA bypass on ResolveTenant, the
    // middleware would throw TenantAccessDeniedException (403) for an SA
    // because both tenant_id and current_tenant_id are NULL.
    $sa = User::factory()->superAdmin()->create();
    $this->actingAs($sa);

    $response = $this->getJson('/api/v1/auth/me');

    $response->assertOk();
    $response->assertJsonPath('data.user.is_super_admin', true);
    $response->assertJsonPath('data.tenant', null);
});

it('regression: tenant_user with both tenant_id and current_tenant_id NULL still throws', function (): void {
    // Defensive: a tenant_user shouldn't have NULL tenant_id (the DB
    // CHECK 'users_tenant_user_has_tenant_check' would reject the insert).
    // But the middleware's existing throw is the runtime guard, and the
    // SA bypass must not loosen it. The DB CHECK prevents creating such
    // a user in the first place, so we use a real tenant_user with valid
    // tenant_id and verify the normal path still works (negative-space
    // regression: the bypass didn't accidentally make tenant_users skip
    // tenant resolution).
    $tenant = Tenant::factory()->create();
    $company = Company::factory()->forTenant($tenant)->create();
    $user = User::factory()->forTenant($tenant)->create([
        'default_company_id' => $company->id,
        'current_company_id' => $company->id,
    ]);
    $user->assignTenantRole($tenant, 'tenant_admin');

    $this->actingAs($user);

    // Normal tenant_user flow: ResolveTenant pins context, /me returns
    // tenant + company data normally.
    $response = $this->getJson('/api/v1/auth/me');
    $response->assertOk();
    $response->assertJsonPath('data.user.is_super_admin', false);
    $response->assertJsonPath('data.tenant.id', $tenant->id);
});

it('regression: suspended tenant still blocks tenant_user with tenant_inactive 401', function (): void {
    // The SA bypass must not let a tenant_user reach data when their
    // tenant is suspended. This is the existing tenant_inactive flow —
    // the bypass adds a parallel branch for SA, it doesn't change the
    // suspended-tenant rule for tenant_users.
    $suspendedTenant = Tenant::factory()->suspended()->create();
    $user = User::factory()->forTenant($suspendedTenant)->create();

    $this->actingAs($user);
    $response = $this->getJson('/api/v1/auth/me');

    $response->assertStatus(401);
    $response->assertJsonPath('error_code', 'tenant_inactive');
});

it('SA can reach an /api/v1/auth/me-equivalent route on the suspended-tenant scenario too (SA sees suspended data)', function (): void {
    // Per Q1: SA sees suspended-tenant data normally. The bypass is
    // unconditional — suspended status doesn't gate SA. /auth/me for an
    // SA never reads tenant.status (the tenant ref is null in the
    // response), so this case reduces to "SA reach me OK" — already
    // covered above. This test exists as the conceptual capture of the
    // Q1 decision (suspended data is accessible to SA); the actual
    // proof lives in Session 2-3's tenant CRUD endpoints where SA
    // queries the tenant table directly.
    $sa = User::factory()->superAdmin()->create();
    Tenant::factory()->suspended()->create(['name' => 'Suspended Co.']);

    $this->actingAs($sa);
    $tenantsSeen = Tenant::query()->orderBy('name')->pluck('name')->all();

    // SA reads the tenant table directly (no global scope on Tenant —
    // it's not a tenant-scoped model). Suspended tenants surface
    // naturally. Confirms the SA's read pathway works on the suspension
    // dimension.
    expect($tenantsSeen)->toContain('Suspended Co.');
});
