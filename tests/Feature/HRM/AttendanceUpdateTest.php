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
        'full_name' => 'Sokha Chan',
    ]);
});

it('PATCHes notes and returns 200 with the refreshed resource', function (): void {
    $record = AttendanceRecord::factory()->forEmployee($this->employee)->create([
        'notes' => 'original',
    ]);

    $this->actingAs($this->admin);
    $response = $this->patchJson("/api/v1/hrm/attendance/{$record->id}", [
        'notes' => 'updated',
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.notes', 'updated');
});

it('returns 401 when called with no authenticated session', function (): void {
    $record = AttendanceRecord::factory()->forEmployee($this->employee)->create();
    $this->patchJson("/api/v1/hrm/attendance/{$record->id}", ['notes' => 'x'])
        ->assertStatus(401);
});

it('returns 403 when the user lacks hrm.attendance.update permission', function (): void {
    $record = AttendanceRecord::factory()->forEmployee($this->employee)->create();

    $unprivileged = User::factory()->forTenant($this->tenant)->create([
        'default_company_id' => $this->company->id,
        'current_company_id' => $this->company->id,
    ]);

    $this->actingAs($unprivileged)
        ->patchJson("/api/v1/hrm/attendance/{$record->id}", ['notes' => 'x'])
        ->assertStatus(403);
});

it('returns 422 errors.clock_in on invalid time format', function (): void {
    $record = AttendanceRecord::factory()->forEmployee($this->employee)->create();

    $this->actingAs($this->admin)
        ->patchJson("/api/v1/hrm/attendance/{$record->id}", ['clock_in' => '25:00:00'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('clock_in');
});

it('returns 422 errors.clock_out when PATCH would make clock_out precede clock_in (effective values via input fallback)', function (): void {
    // Existing row: clock_in=09:00, clock_out=18:00. PATCH submits
    // only clock_in=22:00, which would make the effective
    // (clock_in=22:00, clock_out=18:00) inconsistent. The closure
    // reads clock_out from the existing row.
    $record = AttendanceRecord::factory()->forEmployee($this->employee)->create([
        'clock_in' => '09:00:00',
        'clock_out' => '18:00:00',
    ]);

    $this->actingAs($this->admin)
        ->patchJson("/api/v1/hrm/attendance/{$record->id}", ['clock_in' => '22:00:00'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('clock_out');
});

it('returns 404 for a cross-tenant record id', function (): void {
    $otherTenant = Tenant::factory()->create();
    $otherCompany = Company::factory()->forTenant($otherTenant)->create();
    $otherEmployee = Employee::factory()->forCompany($otherCompany)->create();
    $foreignRecord = AttendanceRecord::factory()->forEmployee($otherEmployee)->create();

    $this->actingAs($this->admin)
        ->patchJson("/api/v1/hrm/attendance/{$foreignRecord->id}", ['notes' => 'x'])
        ->assertStatus(404);
});

it('PATCH on the same (employee, date) does not trigger uniqueness conflict (ignore-self)', function (): void {
    // Sanity: the uniqueness check must skip the row being updated.
    // Without ignore-self, every PATCH would fail because the row's
    // existing (employee, date) "conflicts" with itself.
    $record = AttendanceRecord::factory()->forEmployee($this->employee)->create([
        'date' => '2026-05-14',
    ]);

    $this->actingAs($this->admin);
    $this->patchJson("/api/v1/hrm/attendance/{$record->id}", [
        'date' => '2026-05-14', // same date — must pass
        'notes' => 'edited',
    ])->assertOk();
});

it('returns 422 errors.date with the named-fields message when PATCH would collide with a different existing row', function (): void {
    // Two rows exist for different dates; PATCH the second to collide
    // with the first. The named-fields message names both fields.
    $first = AttendanceRecord::factory()->forEmployee($this->employee)->create([
        'date' => '2026-05-14',
    ]);
    $second = AttendanceRecord::factory()->forEmployee($this->employee)->create([
        'date' => '2026-05-15',
    ]);

    $this->actingAs($this->admin);
    $response = $this->patchJson("/api/v1/hrm/attendance/{$second->id}", [
        'date' => '2026-05-14', // conflicts with $first
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('date');
    $messages = $response->json('errors.date');
    expect($messages[0])->toContain('Sokha Chan');
    expect($messages[0])->toContain('2026-05-14');
    expect($messages[0])->toContain('already exists');

    // Ensure no rows were mutated by the failed update.
    $first->refresh();
    $second->refresh();
    expect($first->date->toDateString())->toBe('2026-05-14');
    expect($second->date->toDateString())->toBe('2026-05-15');
});

it('returns 422 errors.date when PATCHing ONLY employee_id (not date) would create a cross-row conflict', function (): void {
    // Documents the effective-value behavior of the after() closure.
    // employee A has a record on 2026-05-14; employee B has a record
    // on the same date. PATCHing B's employee_id to A would create
    // a (A, 2026-05-14) collision. The closure reads date from the
    // existing row via input-fallback, so even though date isn't in
    // the payload, the check still fires.
    $employeeB = Employee::factory()->forCompany($this->company)->create([
        'full_name' => 'Other Person',
    ]);

    AttendanceRecord::factory()->forEmployee($this->employee)->create([
        'date' => '2026-05-14',
    ]);
    $bRecord = AttendanceRecord::factory()->forEmployee($employeeB)->create([
        'date' => '2026-05-14',
    ]);

    $this->actingAs($this->admin)
        ->patchJson("/api/v1/hrm/attendance/{$bRecord->id}", [
            'employee_id' => $this->employee->id,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('date');
});
