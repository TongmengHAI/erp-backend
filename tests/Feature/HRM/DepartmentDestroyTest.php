<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────────
// DepartmentDestroyTest — covers DELETE /api/v1/hrm/departments/{department}.
//
// §7.D 5-test pattern note: 422 is N/A — DELETE accepts no body. Idempotency
// IS asserted (second delete on a soft-deleted record returns 404 since the
// global scope hides it). Mirrors EmployeeDestroyTest.
// ─────────────────────────────────────────────────────────────────────────────

use App\Domain\HRM\Models\Department;
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

    $this->department = Department::factory()->forCompany($this->company)->create();
});

it('soft-deletes the department and returns 204', function (): void {
    $this->actingAs($this->admin);
    $response = $this->deleteJson("/api/v1/hrm/departments/{$this->department->id}");

    $response->assertStatus(Response::HTTP_NO_CONTENT);
    expect(Department::query()->find($this->department->id))->toBeNull();
    // Visible only via withTrashed — proves SoftDeletes is the mechanism.
    expect(Department::withTrashed()->find($this->department->id)->deleted_at)->not->toBeNull();
});

it('writes a soft_deleted audit row with the department company_id captured', function (): void {
    $this->actingAs($this->admin);
    $this->deleteJson("/api/v1/hrm/departments/{$this->department->id}");

    $row = AuditLog::query()
        ->where('auditable_type', Department::class)
        ->where('auditable_id', $this->department->id)
        ->where('action', 'soft_deleted')
        ->first();

    expect($row)->not->toBeNull();
    expect($row->tenant_id)->toBe($this->tenant->id);
    expect($row->company_id)->toBe($this->company->id);
});

it('returns 401 when called with no authenticated session', function (): void {
    $this->deleteJson("/api/v1/hrm/departments/{$this->department->id}")
        ->assertStatus(401);
});

it('returns 403 when the authenticated user lacks hrm.department.delete permission', function (): void {
    $viewer = User::factory()->forTenant($this->tenant)->create([
        'default_company_id' => $this->company->id,
        'current_company_id' => $this->company->id,
    ]);
    $viewer->assignTenantRole($this->tenant, 'viewer');

    $this->actingAs($viewer)
        ->deleteJson("/api/v1/hrm/departments/{$this->department->id}")
        ->assertStatus(403);
});

it('returns 404 on a second delete (idempotency: soft-deleted records are invisible)', function (): void {
    $this->actingAs($this->admin);
    $this->deleteJson("/api/v1/hrm/departments/{$this->department->id}")
        ->assertStatus(Response::HTTP_NO_CONTENT);
    $this->deleteJson("/api/v1/hrm/departments/{$this->department->id}")
        ->assertStatus(404);
});

it('returns 404 cross-tenant — admin cannot delete a department in another tenant', function (): void {
    $otherTenant = Tenant::factory()->create();
    $otherCompany = Company::factory()->forTenant($otherTenant)->create();
    $other = Department::factory()->forCompany($otherCompany)->create();

    $this->actingAs($this->admin)
        ->deleteJson("/api/v1/hrm/departments/{$other->id}")
        ->assertStatus(404);
});

it('returns 404 cross-company — admin in company X cannot delete a department in company Y', function (): void {
    $otherCompany = Company::factory()->forTenant($this->tenant)->create();
    $other = Department::factory()->forCompany($otherCompany)->create();

    $this->actingAs($this->admin)
        ->deleteJson("/api/v1/hrm/departments/{$other->id}")
        ->assertStatus(404);
});
