<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────────
// LeaveRequestShowTest — covers GET /api/v1/hrm/leave-requests/{id}.
// §7.D 5-test pattern + cross-tenant + cross-company isolation. Verifies the
// approval block shape (null for pending, populated for decided).
// ─────────────────────────────────────────────────────────────────────────────

use App\Domain\HRM\Enums\DayPart;
use App\Domain\HRM\Models\Employee;
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

    $this->employee = Employee::factory()->forCompany($this->company)->create([
        'employee_code' => 'E-9001',
        'full_name' => 'Test Person',
    ]);
});

it('returns 200 with the full resource shape, including nested employee block', function (): void {
    $request = LeaveRequest::factory()->forEmployee($this->employee)->create([
        'reason' => 'Detail visible',
    ]);

    $this->actingAs($this->admin);
    $response = $this->getJson("/api/v1/hrm/leave-requests/{$request->id}");

    $response->assertOk();
    $response->assertJsonPath('data.id', $request->id);
    $response->assertJsonPath('data.employee.id', $this->employee->id);
    $response->assertJsonPath('data.employee.employee_code', 'E-9001');
    $response->assertJsonPath('data.employee.full_name', 'Test Person');
    $response->assertJsonPath('data.reason', 'Detail visible');
    $response->assertJsonPath('data.status', 'pending');
    // day_part included; default is full_day for factory rows.
    $response->assertJsonPath('data.day_part', 'full_day');
    // Approval block is null on pending rows.
    $response->assertJsonPath('data.approval', null);
});

it('returns day_part=morning on a half-day request', function (): void {
    // Round-trip the half-day shape through the detail endpoint so the
    // frontend's date-label composable has a fixture to consume.
    $request = LeaveRequest::factory()
        ->forEmployee($this->employee)
        ->halfDay(DayPart::Morning)
        ->create(['start_date' => '2026-11-03']);

    $this->actingAs($this->admin)
        ->getJson("/api/v1/hrm/leave-requests/{$request->id}")
        ->assertOk()
        ->assertJsonPath('data.day_part', 'morning')
        ->assertJsonPath('data.start_date', '2026-11-03')
        ->assertJsonPath('data.end_date', '2026-11-03');
});

it('returns the approval block populated for a decided (approved) row', function (): void {
    $manager = User::factory()->forTenant($this->tenant)->create(['name' => 'Manager User']);
    $request = LeaveRequest::factory()
        ->forEmployee($this->employee)
        ->approved($manager, 'looks good')
        ->create();

    $this->actingAs($this->admin);
    $response = $this->getJson("/api/v1/hrm/leave-requests/{$request->id}");

    $response->assertOk();
    $response->assertJsonPath('data.status', 'approved');
    $response->assertJsonPath('data.approval.approver.id', $manager->id);
    $response->assertJsonPath('data.approval.approver.name', 'Manager User');
    $response->assertJsonPath('data.approval.note', 'looks good');
    expect($response->json('data.approval.approved_at'))->not->toBeNull();
});

it('returns 401 when called with no authenticated session', function (): void {
    $request = LeaveRequest::factory()->forEmployee($this->employee)->create();

    $this->getJson("/api/v1/hrm/leave-requests/{$request->id}")
        ->assertStatus(401);
});

it('returns 403 when the authenticated user lacks hrm.leave_request.view permission', function (): void {
    $request = LeaveRequest::factory()->forEmployee($this->employee)->create();

    $unprivileged = User::factory()->forTenant($this->tenant)->create([
        'default_company_id' => $this->company->id,
        'current_company_id' => $this->company->id,
    ]);

    $this->actingAs($unprivileged)
        ->getJson("/api/v1/hrm/leave-requests/{$request->id}")
        ->assertStatus(403);
});

it('returns 404 for a cross-tenant leave_request id', function (): void {
    $otherTenant = Tenant::factory()->create();
    $otherCompany = Company::factory()->forTenant($otherTenant)->create();
    $otherEmployee = Employee::factory()->forCompany($otherCompany)->create();
    $foreignRequest = LeaveRequest::factory()->forEmployee($otherEmployee)->create();

    $this->actingAs($this->admin)
        ->getJson("/api/v1/hrm/leave-requests/{$foreignRequest->id}")
        ->assertStatus(404);
});

it('returns 404 for a cross-company leave_request id within the same tenant', function (): void {
    $otherCompany = Company::factory()->forTenant($this->tenant)->create();
    $otherEmployee = Employee::factory()->forCompany($otherCompany)->create();
    $foreignRequest = LeaveRequest::factory()->forEmployee($otherEmployee)->create();

    $this->actingAs($this->admin)
        ->getJson("/api/v1/hrm/leave-requests/{$foreignRequest->id}")
        ->assertStatus(404);
});

it('returns 404 for a non-existent id', function (): void {
    $this->actingAs($this->admin)
        ->getJson('/api/v1/hrm/leave-requests/999999')
        ->assertStatus(404);
});
