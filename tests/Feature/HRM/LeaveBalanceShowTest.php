<?php

declare(strict_types=1);

use App\Domain\HRM\Enums\LeaveType;
use App\Domain\HRM\Models\Employee;
use App\Domain\HRM\Models\LeaveBalance;
use App\Domain\HRM\Models\LeaveRequest;
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

it('returns 200 with the full resource shape including consumed_days + remaining_days', function (): void {
    $balance = LeaveBalance::factory()->forEmployee($this->employee)->annual()->create([
        'period_year' => 2026,
        'allocated_days' => 14.0,
        'notes' => 'Standard allocation.',
    ]);

    $this->actingAs($this->admin);
    $response = $this->getJson("/api/v1/hrm/leave-balances/{$balance->id}");

    $response->assertOk();
    $response->assertJsonStructure([
        'data' => ['id', 'employee', 'leave_type', 'period_year', 'allocated_days', 'consumed_days', 'remaining_days', 'notes', 'created_at', 'updated_at'],
    ]);
    // JSON doesn't preserve trailing zeros on float; Laravel encodes 14.0
    // as 14 by default. Cast through float for shape-stable comparison.
    expect((float) $response->json('data.allocated_days'))->toBe(14.0);
    expect((float) $response->json('data.consumed_days'))->toBe(0.0);
    expect((float) $response->json('data.remaining_days'))->toBe(14.0);
});

it('returns 401 when called with no authenticated session', function (): void {
    $balance = LeaveBalance::factory()->forEmployee($this->employee)->annual()->create(['period_year' => 2026]);
    $this->getJson("/api/v1/hrm/leave-balances/{$balance->id}")->assertStatus(401);
});

it('returns 403 when the user lacks hrm.leave_balance.view permission', function (): void {
    $balance = LeaveBalance::factory()->forEmployee($this->employee)->annual()->create(['period_year' => 2026]);
    $unprivileged = User::factory()->forTenant($this->tenant)->create([
        'default_company_id' => $this->company->id,
        'current_company_id' => $this->company->id,
    ]);

    $this->actingAs($unprivileged)
        ->getJson("/api/v1/hrm/leave-balances/{$balance->id}")
        ->assertStatus(403);
});

it('returns 404 for a cross-tenant balance id', function (): void {
    $otherTenant = Tenant::factory()->create();
    $otherCompany = Company::factory()->forTenant($otherTenant)->create();
    $otherEmployee = Employee::factory()->forCompany($otherCompany)->create();
    $foreign = LeaveBalance::factory()->forEmployee($otherEmployee)->annual()->create(['period_year' => 2026]);

    $this->actingAs($this->admin)
        ->getJson("/api/v1/hrm/leave-balances/{$foreign->id}")
        ->assertStatus(404);
});

it('returns 404 for a cross-company balance id within the same tenant', function (): void {
    $otherCompany = Company::factory()->forTenant($this->tenant)->create();
    $otherEmployee = Employee::factory()->forCompany($otherCompany)->create();
    $foreign = LeaveBalance::factory()->forEmployee($otherEmployee)->annual()->create(['period_year' => 2026]);

    $this->actingAs($this->admin)
        ->getJson("/api/v1/hrm/leave-balances/{$foreign->id}")
        ->assertStatus(404);
});

it('embeds consuming_leave_requests — the approved LRs that contribute to consumed_days, sorted DESC by start_date', function (): void {
    // The detail page renders "Consuming Leave Requests" from this list
    // in a single round-trip. Mirror of Branch detail's "Employees at
    // this branch" cross-module display.
    $manager = User::factory()->forTenant($this->tenant)->create();
    $balance = LeaveBalance::factory()->forEmployee($this->employee)->annual()->create([
        'period_year' => 2026,
        'allocated_days' => 14.0,
    ]);

    // Three approved LRs that contribute (annual, 2026, approved).
    LeaveRequest::factory()->forEmployee($this->employee)->approved($manager)->create([
        'leave_type' => LeaveType::Annual,
        'start_date' => '2026-03-15',
        'end_date' => '2026-03-17',
    ]);
    LeaveRequest::factory()->forEmployee($this->employee)->approved($manager)->create([
        'leave_type' => LeaveType::Annual,
        'start_date' => '2026-05-10',
        'end_date' => '2026-05-12',
    ]);
    LeaveRequest::factory()->forEmployee($this->employee)->approved($manager)->create([
        'leave_type' => LeaveType::Annual,
        'start_date' => '2026-07-04',
        'end_date' => '2026-07-04',
    ]);
    // Distractors that MUST NOT appear in the list:
    //  • pending (status filter)
    //  • sick (different leave_type)
    //  • 2025 annual (different period_year — EXTRACT(YEAR FROM start_date))
    //  • soft-deleted (status filter via the trait scope)
    LeaveRequest::factory()->forEmployee($this->employee)->create([
        'leave_type' => LeaveType::Annual,
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-03',
        // pending by default
    ]);
    LeaveRequest::factory()->forEmployee($this->employee)->approved($manager)->create([
        'leave_type' => LeaveType::Sick,
        'start_date' => '2026-04-01',
        'end_date' => '2026-04-01',
    ]);
    LeaveRequest::factory()->forEmployee($this->employee)->approved($manager)->create([
        'leave_type' => LeaveType::Annual,
        'start_date' => '2025-12-15',
        'end_date' => '2025-12-17',
    ]);

    $this->actingAs($this->admin);
    $body = $this->getJson("/api/v1/hrm/leave-balances/{$balance->id}")->assertOk()->json();

    expect($body['data']['consuming_leave_requests'])->toHaveCount(3);
    // Sorted DESC by start_date — most-recent first.
    expect(array_column($body['data']['consuming_leave_requests'], 'start_date'))
        ->toBe(['2026-07-04', '2026-05-10', '2026-03-15']);
    // Brief shape for each row.
    expect(array_keys($body['data']['consuming_leave_requests'][0]))
        ->toBe(['id', 'start_date', 'end_date', 'day_part', 'days_count', 'approved_at']);

    // consumed_days should reflect the three contributing LRs only:
    // 3 (Mar) + 3 (May) + 1 (Jul) = 7.
    expect((float) $body['data']['consumed_days'])->toBe(7.0);
    expect((float) $body['data']['remaining_days'])->toBe(7.0);
});
