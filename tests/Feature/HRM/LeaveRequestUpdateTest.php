<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────────
// LeaveRequestUpdateTest — covers PATCH /api/v1/hrm/leave-requests/{id}.
// §7.D 5-test pattern + the LOAD-BEARING transition-guard tests:
//   - PATCH on approved row → 422 invalid_transition with from=to=approved
//   - PATCH on rejected row → 422 invalid_transition with from=to=rejected
// These assert the FULL exception shape (error_code + from + to) per the
// commitment that the contract pins the fields.
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

    $this->manager = User::factory()->forTenant($this->tenant)->create();
    $this->employee = Employee::factory()->forCompany($this->company)->create();
});

it('updates non-status fields on a pending row and returns 200 with the refreshed resource', function (): void {
    $request = LeaveRequest::factory()->forEmployee($this->employee)->create([
        'reason' => 'old reason',
        'end_date' => '2026-06-05',
        'start_date' => '2026-06-01',
    ]);

    $this->actingAs($this->admin);
    $response = $this->patchJson("/api/v1/hrm/leave-requests/{$request->id}", [
        'reason' => 'updated reason',
        'end_date' => '2026-06-07',
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.reason', 'updated reason');
    $response->assertJsonPath('data.end_date', '2026-06-07');
    $response->assertJsonPath('data.status', 'pending');
});

it('returns 401 when called with no authenticated session', function (): void {
    $request = LeaveRequest::factory()->forEmployee($this->employee)->create();

    $this->patchJson("/api/v1/hrm/leave-requests/{$request->id}", ['reason' => 'x'])
        ->assertStatus(401);
});

it('returns 403 when the user lacks hrm.leave_request.update permission', function (): void {
    $request = LeaveRequest::factory()->forEmployee($this->employee)->create();

    $unprivileged = User::factory()->forTenant($this->tenant)->create([
        'default_company_id' => $this->company->id,
        'current_company_id' => $this->company->id,
    ]);

    $this->actingAs($unprivileged)
        ->patchJson("/api/v1/hrm/leave-requests/{$request->id}", ['reason' => 'x'])
        ->assertStatus(403);
});

it('returns 422 when end_date is before start_date', function (): void {
    $request = LeaveRequest::factory()->forEmployee($this->employee)->create();

    $this->actingAs($this->admin)
        ->patchJson("/api/v1/hrm/leave-requests/{$request->id}", [
            'start_date' => '2026-06-10',
            'end_date' => '2026-06-05',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('end_date');
});

it('returns 404 for a cross-tenant leave_request id', function (): void {
    $otherTenant = Tenant::factory()->create();
    $otherCompany = Company::factory()->forTenant($otherTenant)->create();
    $otherEmployee = Employee::factory()->forCompany($otherCompany)->create();
    $foreignRequest = LeaveRequest::factory()->forEmployee($otherEmployee)->create();

    $this->actingAs($this->admin)
        ->patchJson("/api/v1/hrm/leave-requests/{$foreignRequest->id}", ['reason' => 'x'])
        ->assertStatus(404);
});

// ─── Transition guard — the LOAD-BEARING tests ───────────────────────────────

it('LOAD-BEARING: PATCH on an approved row returns 422 with error_code=invalid_transition + from=approved + to=approved', function (): void {
    $request = LeaveRequest::factory()
        ->forEmployee($this->employee)
        ->approved($this->manager, 'previously approved')
        ->create();

    $this->actingAs($this->admin);
    $response = $this->patchJson("/api/v1/hrm/leave-requests/{$request->id}", [
        'reason' => 'try to edit a decided row',
    ]);

    $response->assertStatus(422);
    // Pin the full contract shape — error_code + from + to. If a future
    // change drops these fields, this test fails loudly.
    $response->assertJsonPath('error_code', 'invalid_transition');
    $response->assertJsonPath('from', 'approved');
    $response->assertJsonPath('to', 'approved');
});

it('LOAD-BEARING: PATCH on a rejected row returns 422 with error_code=invalid_transition + from=rejected + to=rejected', function (): void {
    $request = LeaveRequest::factory()
        ->forEmployee($this->employee)
        ->rejected($this->manager, 'previously rejected')
        ->create();

    $this->actingAs($this->admin);
    $response = $this->patchJson("/api/v1/hrm/leave-requests/{$request->id}", [
        'reason' => 'try to edit a rejected row',
    ]);

    $response->assertStatus(422);
    $response->assertJsonPath('error_code', 'invalid_transition');
    $response->assertJsonPath('from', 'rejected');
    $response->assertJsonPath('to', 'rejected');
});

// ─── Day-part scenarios on PATCH ─────────────────────────────────────────────

it('PATCHes day_part from full_day to morning when the dates already collapse to a single date', function (): void {
    $request = LeaveRequest::factory()->forEmployee($this->employee)->create([
        'start_date' => '2026-10-01',
        'end_date' => '2026-10-01',
    ]);

    $this->actingAs($this->admin);
    $response = $this->patchJson("/api/v1/hrm/leave-requests/{$request->id}", [
        'day_part' => 'morning',
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.day_part', 'morning');
});

it('returns 422 when PATCHing day_part=morning on a row whose existing dates differ', function (): void {
    // The closure rule reads effective post-patch values via route
    // binding, so this also gets caught at the FormRequest layer
    // rather than slipping through to the DB CHECK (which would 500
    // instead of 422).
    $request = LeaveRequest::factory()->forEmployee($this->employee)->create([
        'start_date' => '2026-10-05',
        'end_date' => '2026-10-09',
    ]);

    $this->actingAs($this->admin)
        ->patchJson("/api/v1/hrm/leave-requests/{$request->id}", [
            'day_part' => 'morning',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('day_part');
});

it('returns 422 when PATCHing end_date to a different date on a half-day row', function (): void {
    $request = LeaveRequest::factory()
        ->forEmployee($this->employee)
        ->halfDay(DayPart::Morning)
        ->create(['start_date' => '2026-10-12']);

    $this->actingAs($this->admin)
        ->patchJson("/api/v1/hrm/leave-requests/{$request->id}", [
            'end_date' => '2026-10-13',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('end_date');
});

it('does not persist any changes when the pending-only guard rejects the edit', function (): void {
    $request = LeaveRequest::factory()
        ->forEmployee($this->employee)
        ->approved($this->manager, 'original note')
        ->create([
            'reason' => 'original reason',
        ]);

    $this->actingAs($this->admin)
        ->patchJson("/api/v1/hrm/leave-requests/{$request->id}", [
            'reason' => 'should not stick',
        ])
        ->assertStatus(422);

    $fresh = LeaveRequest::query()->find($request->id);
    expect($fresh->reason)->toBe('original reason');
    expect($fresh->approver_note)->toBe('original note');
});
