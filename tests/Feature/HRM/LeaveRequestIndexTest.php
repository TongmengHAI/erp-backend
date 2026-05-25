<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────────
// LeaveRequestIndexTest — covers GET /api/v1/hrm/leave-requests.
// §7.D 5-test pattern + cross-tenant + cross-company isolation + filters.
// Also: soft-deleted-row invisibility (load-bearing for the SoftDeletes trait
// stack — a deleted row that still appears in lists is the same bug class as
// a cross-tenant leak).
// ─────────────────────────────────────────────────────────────────────────────

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

    $this->employee = Employee::factory()->forCompany($this->company)->create();
});

it('returns a paginated list of leave_requests scoped to the current tenant + company', function (): void {
    LeaveRequest::factory()->forEmployee($this->employee)->count(3)->create();

    $this->actingAs($this->admin);
    $response = $this->getJson('/api/v1/hrm/leave-requests');

    $response->assertOk();
    $response->assertJsonStructure([
        'data' => [['id', 'employee_id', 'employee_name', 'leave_type', 'start_date', 'end_date', 'status', 'approved_at', 'approver_name']],
        'meta' => ['current_page', 'per_page', 'total'],
    ]);
    expect($response->json('meta.total'))->toBe(3);
});

it('returns 401 when called with no authenticated session', function (): void {
    $this->getJson('/api/v1/hrm/leave-requests')->assertStatus(401);
});

it('returns 403 when the authenticated user lacks hrm.leave_request.view permission', function (): void {
    $unprivileged = User::factory()->forTenant($this->tenant)->create([
        'default_company_id' => $this->company->id,
        'current_company_id' => $this->company->id,
    ]);
    // No role → no permissions.

    $this->actingAs($unprivileged)
        ->getJson('/api/v1/hrm/leave-requests')
        ->assertStatus(403);
});

it('returns 422 when an invalid status filter value is supplied', function (): void {
    $this->actingAs($this->admin)
        ->getJson('/api/v1/hrm/leave-requests?status=not-real')
        ->assertStatus(422)
        ->assertJsonValidationErrors('status');
});

it('isolates cross-tenant — users in tenant A cannot see leave_requests in tenant B', function (): void {
    LeaveRequest::factory()->forEmployee($this->employee)->count(2)->create([
        'reason' => 'Tenant A marker',
    ]);

    $otherTenant = Tenant::factory()->create();
    $otherCompany = Company::factory()->forTenant($otherTenant)->create();
    $otherEmployee = Employee::factory()->forCompany($otherCompany)->create();
    LeaveRequest::factory()->forEmployee($otherEmployee)->create([
        'reason' => 'Tenant B Leak Marker',
    ]);

    $this->actingAs($this->admin);
    $body = $this->getJson('/api/v1/hrm/leave-requests')->assertOk()->json();

    expect($body['meta']['total'])->toBe(2);
    expect(json_encode($body))->not->toContain('Tenant B Leak Marker');
});

it('isolates cross-company — leave_requests in another company within the same tenant are not listed', function (): void {
    LeaveRequest::factory()->forEmployee($this->employee)->create();

    $otherCompany = Company::factory()->forTenant($this->tenant)->create();
    $otherEmployee = Employee::factory()->forCompany($otherCompany)->create();
    LeaveRequest::factory()->forEmployee($otherEmployee)->create([
        'reason' => 'Other Company Leak Marker',
    ]);

    $this->actingAs($this->admin);
    $body = $this->getJson('/api/v1/hrm/leave-requests')->assertOk()->json();

    expect($body['meta']['total'])->toBe(1);
    expect(json_encode($body))->not->toContain('Other Company Leak Marker');
});

it('filters by ?status=pending and excludes decided rows', function (): void {
    $manager = User::factory()->forTenant($this->tenant)->create();
    LeaveRequest::factory()->forEmployee($this->employee)->count(2)->create(); // pending
    LeaveRequest::factory()->forEmployee($this->employee)->approved($manager)->create();
    LeaveRequest::factory()->forEmployee($this->employee)->rejected($manager)->create();

    $this->actingAs($this->admin);
    $body = $this->getJson('/api/v1/hrm/leave-requests?status=pending')->assertOk()->json();

    expect($body['meta']['total'])->toBe(2);
    foreach ($body['data'] as $row) {
        expect($row['status'])->toBe('pending');
    }
});

it('filters by ?employee_id= to scope to a single employee', function (): void {
    $otherEmployee = Employee::factory()->forCompany($this->company)->create();
    LeaveRequest::factory()->forEmployee($this->employee)->count(2)->create();
    LeaveRequest::factory()->forEmployee($otherEmployee)->create();

    $this->actingAs($this->admin);
    $body = $this->getJson("/api/v1/hrm/leave-requests?employee_id={$this->employee->id}")
        ->assertOk()->json();

    expect($body['meta']['total'])->toBe(2);
    foreach ($body['data'] as $row) {
        expect($row['employee_id'])->toBe($this->employee->id);
    }
});

it('surfaces approver_name and approved_at on decided rows; null on pending rows', function (): void {
    $manager = User::factory()->forTenant($this->tenant)->create(['name' => 'Manager User']);
    LeaveRequest::factory()->forEmployee($this->employee)->create(); // pending
    LeaveRequest::factory()->forEmployee($this->employee)->approved($manager, 'ok')->create();

    $this->actingAs($this->admin);
    $body = $this->getJson('/api/v1/hrm/leave-requests')->assertOk()->json();

    expect($body['meta']['total'])->toBe(2);
    // Default order is created_at desc, so the most recently created row
    // (approved) appears first. Sort defensively by status to find each.
    $pending = collect($body['data'])->firstWhere('status', 'pending');
    $approved = collect($body['data'])->firstWhere('status', 'approved');

    expect($pending['approver_name'])->toBeNull();
    expect($pending['approved_at'])->toBeNull();
    expect($approved['approver_name'])->toBe('Manager User');
    expect($approved['approved_at'])->not->toBeNull();
});

it('hides soft-deleted leave_requests from the index — SoftDeletes is load-bearing', function (): void {
    // A soft-deleted row that still appears in a list is the same bug
    // class as a cross-tenant leak: the trait silently failed and stale
    // data leaked. Pin it explicitly.
    LeaveRequest::factory()->forEmployee($this->employee)->count(2)->create();
    $toDelete = LeaveRequest::factory()->forEmployee($this->employee)->create([
        'reason' => 'Soft-Deleted Marker',
    ]);
    $toDelete->delete();

    $this->actingAs($this->admin);
    $body = $this->getJson('/api/v1/hrm/leave-requests')->assertOk()->json();

    expect($body['meta']['total'])->toBe(2);
    expect(json_encode($body))->not->toContain('Soft-Deleted Marker');
});
