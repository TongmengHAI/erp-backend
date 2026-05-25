<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────────
// LeaveRequestApproveTest — covers POST /api/v1/hrm/leave-requests/{id}/approve.
// §7.D 5-test pattern + the LOAD-BEARING transition-guard tests:
//   - approve an already-approved row → 422 invalid_transition (from=approved, to=approved)
//   - approve a rejected row → 422 invalid_transition (from=rejected, to=approved)
//   - approve without .approve permission → 403 (even with .update — the
//     two permissions are deliberately distinct; .update is for editing
//     pending content, .approve is for decision-making authority).
// All transition tests pin the FULL exception shape (error_code + from + to).
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

it('approves a pending request and returns 200 with status=approved + populated approval block', function (): void {
    $request = LeaveRequest::factory()->forEmployee($this->employee)->create();

    $this->actingAs($this->manager);
    $response = $this->postJson("/api/v1/hrm/leave-requests/{$request->id}/approve", [
        'note' => 'Coverage arranged.',
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.status', 'approved');
    $response->assertJsonPath('data.approval.approver.id', $this->manager->id);
    $response->assertJsonPath('data.approval.approver.name', 'Manager User');
    $response->assertJsonPath('data.approval.note', 'Coverage arranged.');
    expect($response->json('data.approval.approved_at'))->not->toBeNull();

    // DB row reflects.
    $fresh = LeaveRequest::query()->find($request->id);
    expect($fresh->status->value)->toBe('approved');
    expect($fresh->approved_by)->toBe($this->manager->id);
    expect($fresh->approved_at)->not->toBeNull();
    expect($fresh->approver_note)->toBe('Coverage arranged.');
});

it('approves with a null note (note is optional at this layer)', function (): void {
    $request = LeaveRequest::factory()->forEmployee($this->employee)->create();

    $this->actingAs($this->manager)
        ->postJson("/api/v1/hrm/leave-requests/{$request->id}/approve", [])
        ->assertOk()
        ->assertJsonPath('data.status', 'approved')
        ->assertJsonPath('data.approval.note', null);
});

it('writes an audit row capturing the status flip pending → approved with the manager as actor', function (): void {
    $request = LeaveRequest::factory()->forEmployee($this->employee)->create();

    $this->actingAs($this->manager)
        ->postJson("/api/v1/hrm/leave-requests/{$request->id}/approve", ['note' => 'ok']);

    $row = AuditLog::query()
        ->where('auditable_type', LeaveRequest::class)
        ->where('auditable_id', $request->id)
        ->where('action', 'updated')
        ->latest('id')
        ->first();

    expect($row)->not->toBeNull();
    expect($row->actor_id)->toBe($this->manager->id);
    expect($row->before['status'] ?? null)->toBe('pending');
    expect($row->after['status'] ?? null)->toBe('approved');
});

it('returns 401 when called with no authenticated session', function (): void {
    $request = LeaveRequest::factory()->forEmployee($this->employee)->create();

    $this->postJson("/api/v1/hrm/leave-requests/{$request->id}/approve", [])
        ->assertStatus(401);
});

it('LOAD-BEARING: returns 403 when the user has .update but lacks .approve — distinct permission boundaries', function (): void {
    // Build an ad-hoc role with .view + .create + .update but NO .approve.
    // This proves the deliberate separation — an "editor" persona who
    // can manage pending requests cannot decide them. The chokepoint is
    // the controller's authorizeHrm call, not the FormRequest.
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $editorRole = Role::findOrCreate('editor_no_approve');
    $editorRole->syncPermissions([
        'hrm.leave_request.view',
        'hrm.leave_request.create',
        'hrm.leave_request.update',
        'hrm.leave_request.delete',
        // explicitly NOT .approve
    ]);

    $editor = User::factory()->forTenant($this->tenant)->create([
        'default_company_id' => $this->company->id,
        'current_company_id' => $this->company->id,
    ]);
    $editor->assignTenantRole($this->tenant, 'editor_no_approve');

    $request = LeaveRequest::factory()->forEmployee($this->employee)->create();

    $this->actingAs($editor)
        ->postJson("/api/v1/hrm/leave-requests/{$request->id}/approve", [])
        ->assertStatus(403);

    // Row untouched.
    $fresh = LeaveRequest::query()->find($request->id);
    expect($fresh->status->value)->toBe('pending');
    expect($fresh->approved_by)->toBeNull();
});

it('returns 422 when note exceeds 500 characters', function (): void {
    $request = LeaveRequest::factory()->forEmployee($this->employee)->create();

    $this->actingAs($this->manager)
        ->postJson("/api/v1/hrm/leave-requests/{$request->id}/approve", [
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
        ->postJson("/api/v1/hrm/leave-requests/{$foreignRequest->id}/approve", [])
        ->assertStatus(404);
});

// ─── Transition guard — the LOAD-BEARING tests ───────────────────────────────

it('LOAD-BEARING: double-approve returns 422 with error_code=invalid_transition + from=approved + to=approved', function (): void {
    $request = LeaveRequest::factory()
        ->forEmployee($this->employee)
        ->approved($this->manager, 'first decision')
        ->create();

    $this->actingAs($this->manager);
    $response = $this->postJson("/api/v1/hrm/leave-requests/{$request->id}/approve", [
        'note' => 'try again',
    ]);

    $response->assertStatus(422);
    $response->assertJsonPath('error_code', 'invalid_transition');
    $response->assertJsonPath('from', 'approved');
    $response->assertJsonPath('to', 'approved');

    // Original decision unchanged.
    $fresh = LeaveRequest::query()->find($request->id);
    expect($fresh->approver_note)->toBe('first decision');
});

it('LOAD-BEARING: approving a rejected row returns 422 with error_code=invalid_transition + from=rejected + to=approved', function (): void {
    $request = LeaveRequest::factory()
        ->forEmployee($this->employee)
        ->rejected($this->manager, 'already rejected')
        ->create();

    $this->actingAs($this->manager);
    $response = $this->postJson("/api/v1/hrm/leave-requests/{$request->id}/approve", []);

    $response->assertStatus(422);
    $response->assertJsonPath('error_code', 'invalid_transition');
    $response->assertJsonPath('from', 'rejected');
    $response->assertJsonPath('to', 'approved');
});
