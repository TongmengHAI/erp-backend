<?php

declare(strict_types=1);

use App\Domain\HRM\Enums\LeaveType;
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

it('creates a leave_balance and returns 201 with the full resource', function (): void {
    $this->actingAs($this->admin);
    $response = $this->postJson('/api/v1/hrm/leave-balances', [
        'employee_id' => $this->employee->id,
        'leave_type' => 'annual',
        'period_year' => 2026,
        'allocated_days' => 14.0,
        'notes' => 'Standard allocation.',
    ]);

    $response->assertStatus(201);
    $response->assertJsonPath('data.employee.id', $this->employee->id);
    $response->assertJsonPath('data.leave_type', 'annual');
    $response->assertJsonPath('data.period_year', 2026);
    // JSON encodes 14.0 as 14 (no JSON_PRESERVE_ZERO_FRACTION).
    // Cast through float for shape-stable comparison.
    expect((float) $response->json('data.allocated_days'))->toBe(14.0);
    expect((float) $response->json('data.consumed_days'))->toBe(0.0);
    expect((float) $response->json('data.remaining_days'))->toBe(14.0);
});

it('returns 401 when called with no authenticated session', function (): void {
    $this->postJson('/api/v1/hrm/leave-balances', [
        'employee_id' => $this->employee->id,
        'leave_type' => 'annual',
        'period_year' => 2026,
        'allocated_days' => 14.0,
    ])->assertStatus(401);
});

it('returns 403 when the user lacks hrm.leave_balance.create permission', function (): void {
    $viewer = User::factory()->forTenant($this->tenant)->create([
        'default_company_id' => $this->company->id,
        'current_company_id' => $this->company->id,
    ]);
    $viewer->assignTenantRole($this->tenant, 'viewer');

    $this->actingAs($viewer)
        ->postJson('/api/v1/hrm/leave-balances', [
            'employee_id' => $this->employee->id,
            'leave_type' => 'annual',
            'period_year' => 2026,
            'allocated_days' => 14.0,
        ])->assertStatus(403);
});

it('returns 422 on missing required fields', function (): void {
    $this->actingAs($this->admin)
        ->postJson('/api/v1/hrm/leave-balances', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['employee_id', 'leave_type', 'period_year', 'allocated_days']);
});

it('LOAD-BEARING: returns 422 errors.leave_type when type is unpaid (allocated subset only)', function (): void {
    // Unpaid is in the LeaveType enum but NOT allocated by design —
    // leave_balances rejects it at the FormRequest layer; the DB
    // CHECK is the final guard against bypass.
    $this->actingAs($this->admin)
        ->postJson('/api/v1/hrm/leave-balances', [
            'employee_id' => $this->employee->id,
            'leave_type' => 'unpaid',
            'period_year' => 2026,
            'allocated_days' => 14.0,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('leave_type');
});

it('LOAD-BEARING: returns 422 errors.leave_type when type is "other" (allocated subset only)', function (): void {
    $this->actingAs($this->admin)
        ->postJson('/api/v1/hrm/leave-balances', [
            'employee_id' => $this->employee->id,
            'leave_type' => 'other',
            'period_year' => 2026,
            'allocated_days' => 14.0,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('leave_type');
});

it('LOAD-BEARING: returns 422 errors.employee_id when employee_id points at a foreign-company employee', function (): void {
    // Same scoped-FK shape as Employee.branch_id. Cross-tenant or
    // cross-company employee_id surfaces as 422, not as a silent
    // cross-context pointer.
    $otherCompany = Company::factory()->forTenant($this->tenant)->create();
    $foreign = Employee::factory()->forCompany($otherCompany)->create();

    $this->actingAs($this->admin)
        ->postJson('/api/v1/hrm/leave-balances', [
            'employee_id' => $foreign->id,
            'leave_type' => 'annual',
            'period_year' => 2026,
            'allocated_days' => 14.0,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('employee_id');
});

it('returns 422 errors.leave_type when (employee, type, period_year) tuple already exists', function (): void {
    LeaveBalance::factory()->forEmployee($this->employee)->annual()->create([
        'period_year' => 2026,
    ]);

    $this->actingAs($this->admin)
        ->postJson('/api/v1/hrm/leave-balances', [
            'employee_id' => $this->employee->id,
            'leave_type' => 'annual',
            'period_year' => 2026,
            'allocated_days' => 16.0,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('leave_type');
});

it('allows the same (employee, type) for a different period_year', function (): void {
    LeaveBalance::factory()->forEmployee($this->employee)->annual()->create([
        'period_year' => 2025,
    ]);

    $this->actingAs($this->admin)
        ->postJson('/api/v1/hrm/leave-balances', [
            'employee_id' => $this->employee->id,
            'leave_type' => 'annual',
            'period_year' => 2026,
            'allocated_days' => 14.0,
        ])
        ->assertStatus(201);
});

it('returns 422 when allocated_days is negative', function (): void {
    $this->actingAs($this->admin)
        ->postJson('/api/v1/hrm/leave-balances', [
            'employee_id' => $this->employee->id,
            'leave_type' => 'annual',
            'period_year' => 2026,
            'allocated_days' => -1.0,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('allocated_days');
});

it('returns 422 when allocated_days is not a multiple of 0.5', function (): void {
    $this->actingAs($this->admin)
        ->postJson('/api/v1/hrm/leave-balances', [
            'employee_id' => $this->employee->id,
            'leave_type' => 'annual',
            'period_year' => 2026,
            'allocated_days' => 14.3,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('allocated_days');
});

it('returns 422 when period_year is outside the 2000–2100 range', function (): void {
    $this->actingAs($this->admin)
        ->postJson('/api/v1/hrm/leave-balances', [
            'employee_id' => $this->employee->id,
            'leave_type' => 'annual',
            'period_year' => 26,
            'allocated_days' => 14.0,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('period_year');
});
