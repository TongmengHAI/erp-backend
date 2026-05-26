<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────────
// AttendanceIndexTest — covers GET /api/v1/hrm/attendance.
// §7.D 5-test pattern + cross-tenant + cross-company isolation + filters
// + soft-deleted invisibility.
// ─────────────────────────────────────────────────────────────────────────────

use App\Domain\HRM\Enums\AttendanceStatus;
use App\Domain\HRM\Models\AttendanceRecord;
use App\Domain\HRM\Models\Employee;
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

it('returns a paginated list of attendance records scoped to the current tenant + company', function (): void {
    AttendanceRecord::factory()->forEmployee($this->employee)->count(3)->create();

    $this->actingAs($this->admin);
    $response = $this->getJson('/api/v1/hrm/attendance');

    $response->assertOk();
    $response->assertJsonStructure([
        'data' => [['id', 'employee_id', 'employee_name', 'employee_code', 'date', 'clock_in', 'clock_out', 'status']],
        'meta' => ['current_page', 'per_page', 'total'],
    ]);
    expect($response->json('meta.total'))->toBe(3);
});

it('returns 401 when called with no authenticated session', function (): void {
    $this->getJson('/api/v1/hrm/attendance')->assertStatus(401);
});

it('returns 403 when the authenticated user lacks hrm.attendance.view permission', function (): void {
    $unprivileged = User::factory()->forTenant($this->tenant)->create([
        'default_company_id' => $this->company->id,
        'current_company_id' => $this->company->id,
    ]);

    $this->actingAs($unprivileged)
        ->getJson('/api/v1/hrm/attendance')
        ->assertStatus(403);
});

it('returns 422 when an invalid status filter value is supplied', function (): void {
    $this->actingAs($this->admin)
        ->getJson('/api/v1/hrm/attendance?status=not-real')
        ->assertStatus(422)
        ->assertJsonValidationErrors('status');
});

it('isolates cross-tenant — users in tenant A cannot see attendance in tenant B', function (): void {
    AttendanceRecord::factory()->forEmployee($this->employee)->count(2)->create([
        'notes' => 'Tenant A marker',
    ]);

    $otherTenant = Tenant::factory()->create();
    $otherCompany = Company::factory()->forTenant($otherTenant)->create();
    $otherEmployee = Employee::factory()->forCompany($otherCompany)->create();
    AttendanceRecord::factory()->forEmployee($otherEmployee)->create([
        'notes' => 'Tenant B Leak Marker',
    ]);

    $this->actingAs($this->admin);
    $body = $this->getJson('/api/v1/hrm/attendance')->assertOk()->json();

    expect($body['meta']['total'])->toBe(2);
    expect(json_encode($body))->not->toContain('Tenant B Leak Marker');
});

it('isolates cross-company — attendance in another company within the same tenant is not listed', function (): void {
    AttendanceRecord::factory()->forEmployee($this->employee)->create();

    $otherCompany = Company::factory()->forTenant($this->tenant)->create();
    $otherEmployee = Employee::factory()->forCompany($otherCompany)->create();
    AttendanceRecord::factory()->forEmployee($otherEmployee)->create([
        'notes' => 'Other Company Leak Marker',
    ]);

    $this->actingAs($this->admin);
    $body = $this->getJson('/api/v1/hrm/attendance')->assertOk()->json();

    expect($body['meta']['total'])->toBe(1);
    expect(json_encode($body))->not->toContain('Other Company Leak Marker');
});

it('filters by ?status=absent', function (): void {
    AttendanceRecord::factory()->forEmployee($this->employee)->count(2)->create(); // present default
    AttendanceRecord::factory()->forEmployee($this->employee)->absent()->create();

    $this->actingAs($this->admin);
    $body = $this->getJson('/api/v1/hrm/attendance?status=absent')->assertOk()->json();

    expect($body['meta']['total'])->toBe(1);
    expect($body['data'][0]['status'])->toBe('absent');
});

it('filters by ?employee_id=', function (): void {
    $otherEmployee = Employee::factory()->forCompany($this->company)->create();
    AttendanceRecord::factory()->forEmployee($this->employee)->count(2)->create();
    AttendanceRecord::factory()->forEmployee($otherEmployee)->create();

    $this->actingAs($this->admin);
    $body = $this->getJson("/api/v1/hrm/attendance?employee_id={$this->employee->id}")
        ->assertOk()->json();

    expect($body['meta']['total'])->toBe(2);
    foreach ($body['data'] as $row) {
        expect($row['employee_id'])->toBe($this->employee->id);
    }
});

it('filters by ?from= and ?to= (inclusive date window)', function (): void {
    AttendanceRecord::factory()->forEmployee($this->employee)->create(['date' => '2026-05-10']);
    AttendanceRecord::factory()->forEmployee($this->employee)->create(['date' => '2026-05-15']);
    AttendanceRecord::factory()->forEmployee($this->employee)->create(['date' => '2026-05-20']);

    $this->actingAs($this->admin);
    $body = $this->getJson('/api/v1/hrm/attendance?from=2026-05-12&to=2026-05-18')
        ->assertOk()->json();

    expect($body['meta']['total'])->toBe(1);
    expect($body['data'][0]['date'])->toBe('2026-05-15');
});

it('default sort is date DESC (newest first)', function (): void {
    AttendanceRecord::factory()->forEmployee($this->employee)->create(['date' => '2026-05-10']);
    AttendanceRecord::factory()->forEmployee($this->employee)->create(['date' => '2026-05-20']);
    AttendanceRecord::factory()->forEmployee($this->employee)->create(['date' => '2026-05-15']);

    $this->actingAs($this->admin);
    $body = $this->getJson('/api/v1/hrm/attendance')->assertOk()->json();

    expect(array_column($body['data'], 'date'))->toBe(['2026-05-20', '2026-05-15', '2026-05-10']);
});

it('hides soft-deleted attendance records from the index', function (): void {
    AttendanceRecord::factory()->forEmployee($this->employee)->count(2)->create();
    $toDelete = AttendanceRecord::factory()->forEmployee($this->employee)->create([
        'notes' => 'Soft-Deleted Marker',
    ]);
    $toDelete->delete();

    $this->actingAs($this->admin);
    $body = $this->getJson('/api/v1/hrm/attendance')->assertOk()->json();

    expect($body['meta']['total'])->toBe(2);
    expect(json_encode($body))->not->toContain('Soft-Deleted Marker');
});

it('exposes status enum value on each row (covering present, absent, late, on_leave, half_day)', function (): void {
    AttendanceRecord::factory()->forEmployee($this->employee)->create(['status' => AttendanceStatus::Present]);
    AttendanceRecord::factory()->forEmployee($this->employee)->absent()->create();
    AttendanceRecord::factory()->forEmployee($this->employee)->late()->create();
    AttendanceRecord::factory()->forEmployee($this->employee)->onLeave()->create();
    AttendanceRecord::factory()->forEmployee($this->employee)->halfDay()->create();

    $this->actingAs($this->admin);
    $body = $this->getJson('/api/v1/hrm/attendance?per_page=100')->assertOk()->json();

    $statuses = array_column($body['data'], 'status');
    expect($statuses)->toContain('present', 'absent', 'late', 'on_leave', 'half_day');
});
