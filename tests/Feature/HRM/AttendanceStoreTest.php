<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────────
// AttendanceStoreTest — covers POST /api/v1/hrm/attendance.
// §7.D 5-test pattern + cross-tenant + cross-company isolation. Three
// LOAD-BEARING tests called out in the slice plan:
//   - Scoped employee_id 422 (foreign-tenant + foreign-company FK)
//   - Uniqueness (employee_id, date) 422 with the named-fields message
//     "Attendance for {employee name} on {date} already exists."
//   - clock_out >= clock_in 422 via the FormRequest's after() closure
//     (the raw-insert DB CHECK regression is in UpdateAttendanceActionTest).
// ─────────────────────────────────────────────────────────────────────────────

use App\Domain\HRM\Models\AttendanceRecord;
use App\Domain\HRM\Models\Employee;
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

    $this->employee = Employee::factory()->forCompany($this->company)->create([
        'full_name' => 'Sokha Chan',
    ]);
});

function validAttendancePayload(int $employeeId, array $overrides = []): array
{
    return array_merge([
        'employee_id' => $employeeId,
        'date' => '2026-05-14',
        'clock_in' => '09:00:00',
        'clock_out' => '18:00:00',
        'status' => 'present',
        'notes' => null,
    ], $overrides);
}

it('creates an attendance record and returns 201 with the full resource', function (): void {
    $this->actingAs($this->admin);
    $response = $this->postJson('/api/v1/hrm/attendance', validAttendancePayload($this->employee->id));

    $response->assertStatus(Response::HTTP_CREATED);
    $response->assertJsonPath('data.status', 'present');
    $response->assertJsonPath('data.employee.id', $this->employee->id);
    $response->assertJsonPath('data.clock_in', '09:00:00');
    $response->assertJsonPath('data.clock_out', '18:00:00');

    $row = AttendanceRecord::query()->latest('id')->firstOrFail();
    expect($row->tenant_id)->toBe($this->tenant->id);
    expect($row->company_id)->toBe($this->company->id);
});

it('writes an audit row with non-null tenant_id + company_id + actor_id', function (): void {
    $this->actingAs($this->admin);
    $this->postJson('/api/v1/hrm/attendance', validAttendancePayload($this->employee->id));

    $row = AuditLog::query()
        ->where('auditable_type', AttendanceRecord::class)
        ->where('action', 'created')
        ->latest('id')
        ->first();

    expect($row->tenant_id)->toBe($this->tenant->id);
    expect($row->company_id)->toBe($this->company->id);
    expect($row->actor_id)->toBe($this->admin->id);
});

it('returns 401 when called with no authenticated session', function (): void {
    $this->postJson('/api/v1/hrm/attendance', validAttendancePayload($this->employee->id))
        ->assertStatus(401);
});

it('returns 403 when the user lacks hrm.attendance.create permission', function (): void {
    $unprivileged = User::factory()->forTenant($this->tenant)->create([
        'default_company_id' => $this->company->id,
        'current_company_id' => $this->company->id,
    ]);

    $this->actingAs($unprivileged)
        ->postJson('/api/v1/hrm/attendance', validAttendancePayload($this->employee->id))
        ->assertStatus(403);
});

it('returns 422 with field-keyed errors on missing required fields', function (): void {
    $this->actingAs($this->admin);
    $response = $this->postJson('/api/v1/hrm/attendance', [
        // missing employee_id, date, status
        'notes' => 'partial',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['employee_id', 'date', 'status']);
});

it('accepts null clock times (absent records have no clock data)', function (): void {
    $this->actingAs($this->admin);
    $response = $this->postJson('/api/v1/hrm/attendance', validAttendancePayload($this->employee->id, [
        'clock_in' => null,
        'clock_out' => null,
        'status' => 'absent',
    ]));

    $response->assertStatus(Response::HTTP_CREATED);
    $response->assertJsonPath('data.clock_in', null);
    $response->assertJsonPath('data.clock_out', null);
});

it('returns 422 with errors.clock_in when clock_in is not HH:MM:SS', function (): void {
    $this->actingAs($this->admin);
    $this->postJson('/api/v1/hrm/attendance', validAttendancePayload($this->employee->id, [
        'clock_in' => '9:00 AM',
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('clock_in');
});

it('returns 422 with errors.clock_in when the hour is out of range (regex rejects 99:99:99)', function (): void {
    // The user explicitly flagged this case in the plan: a permissive
    // regex like ^\d{2}:\d{2}:\d{2}$ would accept "99:99:99". The
    // actual regex ^([01]\d|2[0-3]):[0-5]\d:[0-5]\d$ rejects it.
    $this->actingAs($this->admin);
    $this->postJson('/api/v1/hrm/attendance', validAttendancePayload($this->employee->id, [
        'clock_in' => '99:99:99',
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('clock_in');
});

it('returns 422 with errors.clock_out when clock_out precedes clock_in (FormRequest after() closure)', function (): void {
    $this->actingAs($this->admin);
    $this->postJson('/api/v1/hrm/attendance', validAttendancePayload($this->employee->id, [
        'clock_in' => '18:00:00',
        'clock_out' => '09:00:00',
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('clock_out');
});

it('returns 422 when status is not in the enum', function (): void {
    $this->actingAs($this->admin);
    $this->postJson('/api/v1/hrm/attendance', validAttendancePayload($this->employee->id, [
        'status' => 'maybe',
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('status');
});

it('returns 422 when notes exceeds 500 characters', function (): void {
    $this->actingAs($this->admin);
    $this->postJson('/api/v1/hrm/attendance', validAttendancePayload($this->employee->id, [
        'notes' => str_repeat('x', 501),
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('notes');
});

// ─── LOAD-BEARING tests ─────────────────────────────────────────────────────

it('LOAD-BEARING: returns 422 when employee_id points at an employee in another tenant (scoped-FK)', function (): void {
    $otherTenant = Tenant::factory()->create();
    $otherCompany = Company::factory()->forTenant($otherTenant)->create();
    $foreignEmployee = Employee::factory()->forCompany($otherCompany)->create();

    $this->actingAs($this->admin);
    $this->postJson('/api/v1/hrm/attendance', validAttendancePayload($foreignEmployee->id))
        ->assertStatus(422)
        ->assertJsonValidationErrors('employee_id');

    expect(AttendanceRecord::query()->count())->toBe(0);
});

it('LOAD-BEARING: returns 422 when employee_id points at an employee in another company within the same tenant', function (): void {
    $otherCompany = Company::factory()->forTenant($this->tenant)->create();
    $foreignEmployee = Employee::factory()->forCompany($otherCompany)->create();

    $this->actingAs($this->admin);
    $this->postJson('/api/v1/hrm/attendance', validAttendancePayload($foreignEmployee->id))
        ->assertStatus(422)
        ->assertJsonValidationErrors('employee_id');
});

it('returns 422 when employee_id points at a soft-deleted employee in the same company', function (): void {
    $deleted = Employee::factory()->forCompany($this->company)->create();
    $deleted->delete();

    $this->actingAs($this->admin);
    $this->postJson('/api/v1/hrm/attendance', validAttendancePayload($deleted->id))
        ->assertStatus(422)
        ->assertJsonValidationErrors('employee_id');
});

it('LOAD-BEARING: returns 422 errors.date with the named-fields message on uniqueness conflict', function (): void {
    // The exact shape the slice plan called out: the error placement
    // is under `date` (good default — date is the more likely typo),
    // but the message text names BOTH fields ("Attendance for Sokha
    // Chan on 2026-05-14 already exists.") so there's no ambiguity
    // about which combination conflicted.
    AttendanceRecord::factory()->forEmployee($this->employee)->create([
        'date' => '2026-05-14',
    ]);

    $this->actingAs($this->admin);
    $response = $this->postJson('/api/v1/hrm/attendance', validAttendancePayload($this->employee->id, [
        'date' => '2026-05-14',
    ]));

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('date');

    $messages = $response->json('errors.date');
    expect($messages)->toBeArray();
    expect($messages[0])->toContain('Sokha Chan');
    expect($messages[0])->toContain('2026-05-14');
    expect($messages[0])->toContain('already exists');
});

it('allows re-creating for the same (employee, date) after the previous row was soft-deleted', function (): void {
    // The partial unique index excludes deleted_at IS NOT NULL rows,
    // so the "deleted a wrong entry, want to re-create" workflow is
    // explicitly supported.
    $existing = AttendanceRecord::factory()->forEmployee($this->employee)->create([
        'date' => '2026-05-14',
    ]);
    $existing->delete();

    $this->actingAs($this->admin);
    $this->postJson('/api/v1/hrm/attendance', validAttendancePayload($this->employee->id, [
        'date' => '2026-05-14',
    ]))->assertStatus(Response::HTTP_CREATED);
});
