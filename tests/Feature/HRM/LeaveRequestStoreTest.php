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
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

// ─── Day-part scenarios ──────────────────────────────────────────────────────

it('creates a full-day single-date request (day_part defaults to full_day on omission)', function (): void {
    // Round-trip test #1 — full_day, single date. day_part omitted; the
    // backend defaults the row to full_day. Response shape carries the
    // field for the frontend's display composable.
    $this->actingAs($this->admin);
    $response = $this->postJson('/api/v1/hrm/leave-requests', validLeaveRequestPayload($this->employee->id, [
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-01',
    ]));

    $response->assertStatus(Response::HTTP_CREATED);
    $response->assertJsonPath('data.day_part', 'full_day');
    $response->assertJsonPath('data.start_date', '2026-08-01');
    $response->assertJsonPath('data.end_date', '2026-08-01');

    $row = LeaveRequest::query()->latest('id')->firstOrFail();
    expect($row->day_part->value)->toBe('full_day');
});

it('creates a full-day multi-date range (day_part=full_day explicit, start != end)', function (): void {
    // Round-trip test #2 — full_day, multi-date range. Explicit value.
    $this->actingAs($this->admin);
    $response = $this->postJson('/api/v1/hrm/leave-requests', validLeaveRequestPayload($this->employee->id, [
        'day_part' => 'full_day',
        'start_date' => '2026-08-05',
        'end_date' => '2026-08-09',
    ]));

    $response->assertStatus(Response::HTTP_CREATED);
    $response->assertJsonPath('data.day_part', 'full_day');
    $response->assertJsonPath('data.start_date', '2026-08-05');
    $response->assertJsonPath('data.end_date', '2026-08-09');
});

it('creates a half-day morning request (day_part=morning, start == end)', function (): void {
    // Round-trip test #3 — morning. start_date MUST equal end_date.
    $this->actingAs($this->admin);
    $response = $this->postJson('/api/v1/hrm/leave-requests', validLeaveRequestPayload($this->employee->id, [
        'day_part' => 'morning',
        'start_date' => '2026-08-15',
        'end_date' => '2026-08-15',
    ]));

    $response->assertStatus(Response::HTTP_CREATED);
    $response->assertJsonPath('data.day_part', 'morning');
    $response->assertJsonPath('data.start_date', '2026-08-15');
    $response->assertJsonPath('data.end_date', '2026-08-15');
});

it('creates a half-day afternoon request', function (): void {
    // Symmetry check — afternoon behaves identically to morning.
    $this->actingAs($this->admin);
    $response = $this->postJson('/api/v1/hrm/leave-requests', validLeaveRequestPayload($this->employee->id, [
        'day_part' => 'afternoon',
        'start_date' => '2026-08-20',
        'end_date' => '2026-08-20',
    ]));

    $response->assertStatus(Response::HTTP_CREATED);
    $response->assertJsonPath('data.day_part', 'afternoon');
});

it('returns 422 errors.end_date when day_part=morning AND start_date != end_date', function (): void {
    // LOAD-BEARING: the single-date invariant for half-day requests.
    // The FormRequest closure catches it before the DB CHECK gets a
    // chance. Surfaces as a field error on end_date (the field the
    // user can reasonably "fix").
    $this->actingAs($this->admin);
    $this->postJson('/api/v1/hrm/leave-requests', validLeaveRequestPayload($this->employee->id, [
        'day_part' => 'morning',
        'start_date' => '2026-08-15',
        'end_date' => '2026-08-16',
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('end_date');
});

it('returns 422 errors.end_date when day_part=afternoon AND start_date != end_date', function (): void {
    $this->actingAs($this->admin);
    $this->postJson('/api/v1/hrm/leave-requests', validLeaveRequestPayload($this->employee->id, [
        'day_part' => 'afternoon',
        'start_date' => '2026-08-15',
        'end_date' => '2026-08-17',
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('end_date');
});

it('returns 422 when day_part is not in the enum', function (): void {
    $this->actingAs($this->admin);
    $this->postJson('/api/v1/hrm/leave-requests', validLeaveRequestPayload($this->employee->id, [
        'day_part' => 'evening',
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('day_part');
});

it('LOAD-BEARING: composite DB CHECK rejects a raw INSERT with day_part=morning AND start_date != end_date', function (): void {
    // Bypass the model AND the FormRequest entirely — DB::table()->insert()
    // skips both layers. This proves the composite CHECK fires regardless
    // of application-layer validation. Same regression-protection pattern
    // as the existing leave_requests_approval_consistency_check test.
    //
    // Going through LeaveRequest::create() would let the cast layer or
    // any future model-level mutator catch the inconsistency first; the
    // CHECK would never fire and the test would pass falsely.
    $thrown = false;
    try {
        DB::table('leave_requests')->insert([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'leave_type' => 'annual',
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-03', // ← inconsistent with day_part below
            'day_part' => 'morning',
            // days_count is NOT NULL now (micro-slice added the column).
            // Set a placeholder so the raw INSERT reaches the composite
            // CHECK we're actually testing — otherwise the NOT NULL on
            // days_count fires first and shadows the day_part check.
            'days_count' => 0.5,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    } catch (QueryException $e) {
        $thrown = true;
        expect($e->getMessage())->toContain('leave_requests_day_part_single_date_check');
    }

    expect($thrown)->toBeTrue(
        'Expected the composite CHECK constraint to reject the inconsistent raw INSERT.',
    );
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
