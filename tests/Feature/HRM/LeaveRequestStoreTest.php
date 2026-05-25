<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────────
// LeaveRequestStoreTest — covers POST /api/v1/hrm/leave-requests.
// §7.D 5-test pattern + cross-tenant + cross-company isolation. Includes the
// LOAD-BEARING test that a foreign-tenant employee_id is rejected 422 (the
// scoped-exists Rule::exists pattern). Also pins: status is forced pending
// even if the client smuggles status=approved.
// ─────────────────────────────────────────────────────────────────────────────

use App\Domain\HRM\Models\Employee;
use App\Domain\HRM\Models\LeaveRequest;
use App\Models\Company;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Audit\Models\AuditLog;
use Database\Seeders\Framework\DefaultPermissionsSeeder;
use Database\Seeders\Framework\DefaultRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpFoundation\Response;

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

function validLeaveRequestPayload(int $employeeId, array $overrides = []): array
{
    return array_merge([
        'employee_id' => $employeeId,
        'leave_type' => 'annual',
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-05',
        'reason' => 'Family event.',
    ], $overrides);
}

it('creates a leave_request and returns 201 with the full resource (status=pending, approval=null)', function (): void {
    $this->actingAs($this->admin);
    $response = $this->postJson('/api/v1/hrm/leave-requests', validLeaveRequestPayload($this->employee->id));

    $response->assertStatus(Response::HTTP_CREATED);
    $response->assertJsonPath('data.status', 'pending');
    $response->assertJsonPath('data.approval', null);
    $response->assertJsonPath('data.employee.id', $this->employee->id);

    $row = LeaveRequest::query()->latest('id')->firstOrFail();
    expect($row->tenant_id)->toBe($this->tenant->id);
    expect($row->company_id)->toBe($this->company->id);
});

it('writes an audit row with non-null tenant_id + company_id + actor_id when a leave_request is created', function (): void {
    $this->actingAs($this->admin);
    $this->postJson('/api/v1/hrm/leave-requests', validLeaveRequestPayload($this->employee->id));

    $row = AuditLog::query()
        ->where('auditable_type', LeaveRequest::class)
        ->where('action', 'created')
        ->latest('id')
        ->first();

    expect($row->tenant_id)->toBe($this->tenant->id);
    expect($row->company_id)->toBe($this->company->id);
    expect($row->actor_id)->toBe($this->admin->id);
});

it('returns 401 when called with no authenticated session', function (): void {
    $this->postJson('/api/v1/hrm/leave-requests', validLeaveRequestPayload($this->employee->id))
        ->assertStatus(401);
});

it('returns 403 when the user lacks hrm.leave_request.create permission', function (): void {
    $unprivileged = User::factory()->forTenant($this->tenant)->create([
        'default_company_id' => $this->company->id,
        'current_company_id' => $this->company->id,
    ]);

    $this->actingAs($unprivileged)
        ->postJson('/api/v1/hrm/leave-requests', validLeaveRequestPayload($this->employee->id))
        ->assertStatus(403);
});

it('returns 422 with field-keyed errors on missing required fields', function (): void {
    $this->actingAs($this->admin);
    $response = $this->postJson('/api/v1/hrm/leave-requests', [
        // missing employee_id, leave_type, start_date, end_date
        'reason' => 'partial',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['employee_id', 'leave_type', 'start_date', 'end_date']);
});

it('returns 422 when end_date is before start_date', function (): void {
    $this->actingAs($this->admin);
    $this->postJson('/api/v1/hrm/leave-requests', validLeaveRequestPayload($this->employee->id, [
        'start_date' => '2026-06-10',
        'end_date' => '2026-06-05',
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('end_date');
});

it('returns 422 when leave_type is not in the enum', function (): void {
    $this->actingAs($this->admin);
    $this->postJson('/api/v1/hrm/leave-requests', validLeaveRequestPayload($this->employee->id, [
        'leave_type' => 'fake_type',
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('leave_type');
});

it('LOAD-BEARING: returns 422 when employee_id points at an employee in another tenant', function (): void {
    // The scoped-exists test. A client passes a foreign-tenant employee_id;
    // without the where(tenant_id, current).where(company_id, current) on
    // Rule::exists this would persist a cross-tenant data leak through an
    // unguarded FK. With it, 422 with errors.employee_id.
    $otherTenant = Tenant::factory()->create();
    $otherCompany = Company::factory()->forTenant($otherTenant)->create();
    $foreignEmployee = Employee::factory()->forCompany($otherCompany)->create();

    $this->actingAs($this->admin);
    $this->postJson('/api/v1/hrm/leave-requests', validLeaveRequestPayload($foreignEmployee->id))
        ->assertStatus(422)
        ->assertJsonValidationErrors('employee_id');

    expect(LeaveRequest::query()->count())->toBe(0);
});

it('returns 422 when employee_id points at an employee in another company within the same tenant', function (): void {
    $otherCompany = Company::factory()->forTenant($this->tenant)->create();
    $foreignEmployee = Employee::factory()->forCompany($otherCompany)->create();

    $this->actingAs($this->admin);
    $this->postJson('/api/v1/hrm/leave-requests', validLeaveRequestPayload($foreignEmployee->id))
        ->assertStatus(422)
        ->assertJsonValidationErrors('employee_id');
});

it('returns 422 when employee_id points at a soft-deleted employee in the same company', function (): void {
    $deleted = Employee::factory()->forCompany($this->company)->create();
    $deleted->delete();

    $this->actingAs($this->admin);
    $this->postJson('/api/v1/hrm/leave-requests', validLeaveRequestPayload($deleted->id))
        ->assertStatus(422)
        ->assertJsonValidationErrors('employee_id');
});

it('forces status to pending even if client smuggles status=approved + approval columns in the payload', function (): void {
    // The Action drops these; the FormRequest validation doesn't accept
    // them. Belt and suspenders — pinned via integration.
    $this->actingAs($this->admin);
    $response = $this->postJson('/api/v1/hrm/leave-requests', validLeaveRequestPayload($this->employee->id, [
        'status' => 'approved',
        'approved_by' => 9999,
        'approved_at' => '2026-01-01 00:00:00',
        'approver_note' => 'should not stick',
    ]));

    $response->assertStatus(Response::HTTP_CREATED);
    $response->assertJsonPath('data.status', 'pending');
    $response->assertJsonPath('data.approval', null);

    $row = LeaveRequest::query()->latest('id')->firstOrFail();
    expect($row->status->value)->toBe('pending');
    expect($row->approved_by)->toBeNull();
    expect($row->approved_at)->toBeNull();
    expect($row->approver_note)->toBeNull();
});
