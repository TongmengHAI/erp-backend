<?php

declare(strict_types=1);

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

    $this->employee = Employee::factory()->forCompany($this->company)->create([
        'employee_code' => 'E-9001',
        'full_name' => 'Test Person',
    ]);
});

it('returns 200 with the full resource shape including nested employee block', function (): void {
    $record = AttendanceRecord::factory()->forEmployee($this->employee)->create([
        'date' => '2026-05-14',
        'clock_in' => '09:45:00',
        'clock_out' => '18:00:00',
        'notes' => 'Train delay.',
    ]);

    $this->actingAs($this->admin);
    $response = $this->getJson("/api/v1/hrm/attendance/{$record->id}");

    $response->assertOk();
    $response->assertJsonPath('data.id', $record->id);
    $response->assertJsonPath('data.employee.id', $this->employee->id);
    $response->assertJsonPath('data.employee.employee_code', 'E-9001');
    $response->assertJsonPath('data.employee.full_name', 'Test Person');
    $response->assertJsonPath('data.date', '2026-05-14');
    $response->assertJsonPath('data.clock_in', '09:45:00');
    $response->assertJsonPath('data.clock_out', '18:00:00');
    $response->assertJsonPath('data.notes', 'Train delay.');
});

it('returns 401 when called with no authenticated session', function (): void {
    $record = AttendanceRecord::factory()->forEmployee($this->employee)->create();

    $this->getJson("/api/v1/hrm/attendance/{$record->id}")->assertStatus(401);
});

it('returns 403 when the user lacks hrm.attendance.view permission', function (): void {
    $record = AttendanceRecord::factory()->forEmployee($this->employee)->create();

    $unprivileged = User::factory()->forTenant($this->tenant)->create([
        'default_company_id' => $this->company->id,
        'current_company_id' => $this->company->id,
    ]);

    $this->actingAs($unprivileged)
        ->getJson("/api/v1/hrm/attendance/{$record->id}")
        ->assertStatus(403);
});

it('returns 404 for a cross-tenant record id', function (): void {
    $otherTenant = Tenant::factory()->create();
    $otherCompany = Company::factory()->forTenant($otherTenant)->create();
    $otherEmployee = Employee::factory()->forCompany($otherCompany)->create();
    $foreignRecord = AttendanceRecord::factory()->forEmployee($otherEmployee)->create();

    $this->actingAs($this->admin)
        ->getJson("/api/v1/hrm/attendance/{$foreignRecord->id}")
        ->assertStatus(404);
});

it('returns 404 for a cross-company record id within the same tenant', function (): void {
    $otherCompany = Company::factory()->forTenant($this->tenant)->create();
    $otherEmployee = Employee::factory()->forCompany($otherCompany)->create();
    $foreignRecord = AttendanceRecord::factory()->forEmployee($otherEmployee)->create();

    $this->actingAs($this->admin)
        ->getJson("/api/v1/hrm/attendance/{$foreignRecord->id}")
        ->assertStatus(404);
});

it('returns 404 for a non-existent id', function (): void {
    $this->actingAs($this->admin)
        ->getJson('/api/v1/hrm/attendance/999999')
        ->assertStatus(404);
});

it('renders an absent record with null clock times', function (): void {
    $record = AttendanceRecord::factory()->forEmployee($this->employee)->absent()->create();

    $this->actingAs($this->admin);
    $response = $this->getJson("/api/v1/hrm/attendance/{$record->id}");

    $response->assertOk();
    $response->assertJsonPath('data.status', 'absent');
    $response->assertJsonPath('data.clock_in', null);
    $response->assertJsonPath('data.clock_out', null);
});
