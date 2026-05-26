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

    $this->employee = Employee::factory()->forCompany($this->company)->create();
});

it('soft-deletes an attendance record and returns 204', function (): void {
    $record = AttendanceRecord::factory()->forEmployee($this->employee)->create();

    $this->actingAs($this->admin)
        ->deleteJson("/api/v1/hrm/attendance/{$record->id}")
        ->assertStatus(204);

    expect(AttendanceRecord::query()->find($record->id))->toBeNull();
    expect(AttendanceRecord::query()->withTrashed()->find($record->id))->not->toBeNull();
});

it('returns 401 when called with no authenticated session', function (): void {
    $record = AttendanceRecord::factory()->forEmployee($this->employee)->create();
    $this->deleteJson("/api/v1/hrm/attendance/{$record->id}")->assertStatus(401);
});

it('returns 403 when the user lacks hrm.attendance.delete permission', function (): void {
    $record = AttendanceRecord::factory()->forEmployee($this->employee)->create();

    $unprivileged = User::factory()->forTenant($this->tenant)->create([
        'default_company_id' => $this->company->id,
        'current_company_id' => $this->company->id,
    ]);

    $this->actingAs($unprivileged)
        ->deleteJson("/api/v1/hrm/attendance/{$record->id}")
        ->assertStatus(403);
});

it('returns 404 for a cross-tenant record id', function (): void {
    $otherTenant = Tenant::factory()->create();
    $otherCompany = Company::factory()->forTenant($otherTenant)->create();
    $otherEmployee = Employee::factory()->forCompany($otherCompany)->create();
    $foreignRecord = AttendanceRecord::factory()->forEmployee($otherEmployee)->create();

    $this->actingAs($this->admin)
        ->deleteJson("/api/v1/hrm/attendance/{$foreignRecord->id}")
        ->assertStatus(404);

    expect(AttendanceRecord::query()->withoutGlobalScopes()->find($foreignRecord->id)->deleted_at)
        ->toBeNull();
});

it('returns 404 on a second DELETE (idempotency: soft-deleted rows are invisible to route-model binding)', function (): void {
    $record = AttendanceRecord::factory()->forEmployee($this->employee)->create();

    $this->actingAs($this->admin);
    $this->deleteJson("/api/v1/hrm/attendance/{$record->id}")->assertStatus(204);
    $this->deleteJson("/api/v1/hrm/attendance/{$record->id}")->assertStatus(404);
});
