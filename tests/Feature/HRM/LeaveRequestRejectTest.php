<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────────
// LeaveRequestRejectTest — covers POST /api/v1/hrm/leave-requests/{id}/reject.
// Mirror of LeaveRequestApproveTest with status=rejected.
// Transition tests pin the FULL exception shape (error_code + from + to).
// ─────────────────────────────────────────────────────────────────────────────

use App\Domain\HRM\Models\Employee;
use App\Domain\HRM\Models\LeaveRequest;
use App\Models\Company;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\Framework\DefaultPermissionsSeeder;
use Database\Seeders\Framework\DefaultRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withHeader('Origin', 'http://localhost');
    $this->seed([DefaultPermissionsSeeder::class, DefaultRolesSeeder::class]);

    $this->tenant = Tenant::factory()->create();
    $this->company = Company::factory()->forTenant($this->tenant)->create();
    $this->manager = User::factory()->forTenant($this->tenant)->create([
        'name' => 'Manager User',
        'default_company_id' => $this->company->id,
        'current_company_id' => $this->company->id,
    ]);
    $this->manager->assignTenantRole($this->tenant, 'tenant_admin');

    $this->employee = Employee::factory()->forCompany($this->company)->create();
});

it('rejects a pending request and returns 200 with status=rejected + populated approval block', function (): void {
    $request = LeaveRequest::factory()->forEmployee($this->employee)->create();

    $this->actingAs($this->manager);
    $response = $this->postJson("/api/v1/hrm/leave-requests/{$request->id}/reject", [
        'note' => 'Capacity full.',
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.status', 'rejected');
    $response->assertJsonPath('data.approval.approver.id', $this->manager->id);
    $response->assertJsonPath('data.approval.approver.name', 'Manager User');
    $response->assertJsonPath('data.approval.note', 'Capacity full.');

    $fresh = LeaveRequest::query()->find($request->id);
    expect($fresh->status->value)->toBe('rejected');
    expect($fresh->approved_by)->toBe($this->manager->id);
    expect($fresh->approved_at)->not->toBeNull();
});

it('returns 401 when called with no authenticated session', function (): void {
    $request = LeaveRequest::factory()->forEmployee($this->employee)->create();

    $this->postJson("/api/v1/hrm/leave-requests/{$request->id}/reject", [])
        ->assertStatus(401);
});

it('returns 403 when the user lacks .approve (the decision-making permission gates both /approve and /reject)', function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $editorRole = Role::findOrCreate('editor_no_approve_reject');
    $editorRole->syncPermissions([
        'hrm.leave_request.view',
        'hrm.leave_request.create',
        'hrm.leave_request.update',
    ]);

    $editor = User::factory()->forTenant($this->tenant)->create([
        'default_company_id' => $this->company->id,
        'current_company_id' => $this->company->id,
    ]);
    $editor->assignTenantRole($this->tenant, 'editor_no_approve_reject');

    $request = LeaveRequest::factory()->forEmployee($this->employee)->create();

    $this->actingAs($editor)
        ->postJson("/api/v1/hrm/leave-requests/{$request->id}/reject", [])
        ->assertStatus(403);
});

it('returns 422 when note exceeds 500 characters', function (): void {
    $request = LeaveRequest::factory()->forEmployee($this->employee)->create();

    $this->actingAs($this->manager)
        ->postJson("/api/v1/hrm/leave-requests/{$request->id}/reject", [
            'note' => str_repeat('x', 501),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('note');
});

it('returns 404 for a cross-tenant leave_request id', function (): void {
    $otherTenant = Tenant::factory()->create();
    $otherCompany = Company::factory()->forTenant($otherTenant)->create();
    $otherEmployee = Employee::factory()->forCompany($otherCompany)->create();
    $foreignRequest = LeaveRequest::factory()->forEmployee($otherEmployee)->create();

    $this->actingAs($this->manager)
        ->postJson("/api/v1/hrm/leave-requests/{$foreignRequest->id}/reject", [])
        ->assertStatus(404);
});

// ─── Transition guard — the LOAD-BEARING tests ───────────────────────────────

it('LOAD-BEARING: rejecting an already-rejected row returns 422 with error_code=invalid_transition + from=rejected + to=rejected', function (): void {
    $request = LeaveRequest::factory()
        ->forEmployee($this->employee)
        ->rejected($this->manager, 'first reject')
        ->create();

    $this->actingAs($this->manager);
    $response = $this->postJson("/api/v1/hrm/leave-requests/{$request->id}/reject", []);

    $response->assertStatus(422);
    $response->assertJsonPath('error_code', 'invalid_transition');
    $response->assertJsonPath('from', 'rejected');
    $response->assertJsonPath('to', 'rejected');
});

it('LOAD-BEARING: rejecting an approved row returns 422 with error_code=invalid_transition + from=approved + to=rejected', function (): void {
    $request = LeaveRequest::factory()
        ->forEmployee($this->employee)
        ->approved($this->manager)
        ->create();

    $this->actingAs($this->manager);
    $response = $this->postJson("/api/v1/hrm/leave-requests/{$request->id}/reject", []);

    $response->assertStatus(422);
    $response->assertJsonPath('error_code', 'invalid_transition');
    $response->assertJsonPath('from', 'approved');
    $response->assertJsonPath('to', 'rejected');
});
