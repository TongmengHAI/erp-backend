<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────────
// LeaveBalanceIndexTest — covers GET /api/v1/hrm/leave-balances.
// 5-test pattern + cross-tenant + cross-company isolation + filters.
// The list response carries consumed_days + remaining_days computed by
// LeaveBalanceQueryService — assert their presence on the brief shape.
// ─────────────────────────────────────────────────────────────────────────────

use App\Domain\HRM\Models\Employee;
use App\Domain\HRM\Models\LeaveBalance;
use App\Models\Company;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\Framework\DefaultPermissionsSeeder;
use Database\Seeders\Framework\DefaultRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withHeader('Origin', 'http://localhost');
    $this->seed([DefaultPermissionsSeeder::class, DefaultRolesSeeder::class]);

    $this->tenant = Tenant::factory()->create();
    $this->company = Company::factory()->forTenant($this->tenant)->create();
    $this->admin = User::factory()->forTenant($this->tenant)->create([
        'default_company_id' => $this->company->id,
        'current_company_id' => $this->company->id,
    ]);
    $this->admin->assignTenantRole($this->tenant, 'tenant_admin');

    $this->employee = Employee::factory()->forCompany($this->company)->create();
});

it('returns a paginated list of balances with consumed_days + remaining_days fields', function (): void {
    LeaveBalance::factory()->forEmployee($this->employee)->annual()->create([
        'period_year' => 2026,
        'allocated_days' => 14.0,
    ]);
    LeaveBalance::factory()->forEmployee($this->employee)->sick()->create([
        'period_year' => 2026,
        'allocated_days' => 7.0,
    ]);

    $this->actingAs($this->admin);
    $response = $this->getJson('/api/v1/hrm/leave-balances');

    $response->assertOk();
    $response->assertJsonStructure([
        'data' => [['id', 'employee_id', 'employee_name', 'leave_type', 'period_year', 'allocated_days', 'consumed_days', 'remaining_days']],
        'meta' => ['current_page', 'per_page', 'total'],
    ]);
    expect($response->json('meta.total'))->toBe(2);

    // No approved LRs yet → consumed=0, remaining=allocated for both rows.
    foreach ($response->json('data') as $row) {
        expect($row['consumed_days'])->toBe(0);
        expect($row['remaining_days'])->toBe($row['allocated_days']);
    }
});

it('returns 401 when called with no authenticated session', function (): void {
    $this->getJson('/api/v1/hrm/leave-balances')->assertStatus(401);
});

it('returns 403 when the user lacks hrm.leave_balance.view permission', function (): void {
    $unprivileged = User::factory()->forTenant($this->tenant)->create([
        'default_company_id' => $this->company->id,
        'current_company_id' => $this->company->id,
    ]);

    $this->actingAs($unprivileged)
        ->getJson('/api/v1/hrm/leave-balances')
        ->assertStatus(403);
});

it('returns 422 when an invalid leave_type filter value is supplied', function (): void {
    $this->actingAs($this->admin)
        ->getJson('/api/v1/hrm/leave-balances?leave_type=unpaid')
        ->assertStatus(422)
        ->assertJsonValidationErrors('leave_type');
});

it('isolates cross-tenant — users in tenant A cannot see balances in tenant B', function (): void {
    LeaveBalance::factory()->forEmployee($this->employee)->annual()->create([
        'period_year' => 2026,
        'notes' => 'Tenant A balance',
    ]);

    $otherTenant = Tenant::factory()->create();
    $otherCompany = Company::factory()->forTenant($otherTenant)->create();
    $otherEmployee = Employee::factory()->forCompany($otherCompany)->create();
    LeaveBalance::factory()->forEmployee($otherEmployee)->annual()->create([
        'period_year' => 2026,
        'notes' => 'Tenant B Leak Marker',
    ]);

    $this->actingAs($this->admin);
    $body = $this->getJson('/api/v1/hrm/leave-balances')->assertOk()->json();

    expect($body['meta']['total'])->toBe(1);
    expect(json_encode($body))->not->toContain('Tenant B Leak Marker');
});

it('isolates cross-company — balances in another company within the same tenant are not listed', function (): void {
    LeaveBalance::factory()->forEmployee($this->employee)->annual()->create([
        'period_year' => 2026,
        'notes' => 'Visible balance',
    ]);

    $otherCompany = Company::factory()->forTenant($this->tenant)->create();
    $otherEmployee = Employee::factory()->forCompany($otherCompany)->create();
    LeaveBalance::factory()->forEmployee($otherEmployee)->annual()->create([
        'period_year' => 2026,
        'notes' => 'Other Company Leak Marker',
    ]);

    $this->actingAs($this->admin);
    $body = $this->getJson('/api/v1/hrm/leave-balances')->assertOk()->json();

    expect($body['meta']['total'])->toBe(1);
    expect(json_encode($body))->not->toContain('Other Company Leak Marker');
});

it('filters by ?employee_id=', function (): void {
    $other = Employee::factory()->forCompany($this->company)->create();
    LeaveBalance::factory()->forEmployee($this->employee)->annual()->create(['period_year' => 2026]);
    LeaveBalance::factory()->forEmployee($other)->annual()->create(['period_year' => 2026]);

    $this->actingAs($this->admin);
    $body = $this->getJson("/api/v1/hrm/leave-balances?employee_id={$this->employee->id}")
        ->assertOk()->json();

    expect($body['meta']['total'])->toBe(1);
    expect($body['data'][0]['employee_id'])->toBe($this->employee->id);
});

it('filters by ?leave_type=sick', function (): void {
    LeaveBalance::factory()->forEmployee($this->employee)->annual()->create(['period_year' => 2026]);
    LeaveBalance::factory()->forEmployee($this->employee)->sick()->create(['period_year' => 2026]);

    $this->actingAs($this->admin);
    $body = $this->getJson('/api/v1/hrm/leave-balances?leave_type=sick')->assertOk()->json();

    expect($body['meta']['total'])->toBe(1);
    expect($body['data'][0]['leave_type'])->toBe('sick');
});

it('filters by ?period_year=', function (): void {
    LeaveBalance::factory()->forEmployee($this->employee)->annual()->create(['period_year' => 2025]);
    LeaveBalance::factory()->forEmployee($this->employee)->annual()->create(['period_year' => 2026]);

    $this->actingAs($this->admin);
    $body = $this->getJson('/api/v1/hrm/leave-balances?period_year=2026')->assertOk()->json();

    expect($body['meta']['total'])->toBe(1);
    expect($body['data'][0]['period_year'])->toBe(2026);
});

it('hides soft-deleted balances from the index', function (): void {
    LeaveBalance::factory()->forEmployee($this->employee)->annual()->create(['period_year' => 2026]);
    $toDelete = LeaveBalance::factory()->forEmployee($this->employee)->sick()->create([
        'period_year' => 2026,
        'notes' => 'Soft-Deleted Marker',
    ]);
    $toDelete->delete();

    $this->actingAs($this->admin);
    $body = $this->getJson('/api/v1/hrm/leave-balances')->assertOk()->json();

    expect($body['meta']['total'])->toBe(1);
    expect(json_encode($body))->not->toContain('Soft-Deleted Marker');
});
