<?php

declare(strict_types=1);

use App\Domain\HRM\Models\Position;
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
});

function validPositionPayload(array $overrides = []): array
{
    return array_merge([
        'code' => 'P-NEW1',
        'title' => 'New Position',
        'description' => 'A new role.',
        'status' => 'active',
    ], $overrides);
}

it('creates a position and returns 201 with the full resource', function (): void {
    $this->actingAs($this->admin);
    $response = $this->postJson('/api/v1/hrm/positions', validPositionPayload());

    $response->assertStatus(Response::HTTP_CREATED);
    $response->assertJsonPath('data.code', 'P-NEW1');
    $response->assertJsonPath('data.title', 'New Position');
    $response->assertJsonPath('data.status', 'active');

    $row = Position::query()->where('code', 'P-NEW1')->firstOrFail();
    expect($row->tenant_id)->toBe($this->tenant->id);
    expect($row->company_id)->toBe($this->company->id);
});

it('writes an audit row with non-null tenant_id + company_id + actor_id', function (): void {
    $this->actingAs($this->admin);
    $this->postJson('/api/v1/hrm/positions', validPositionPayload());

    $row = AuditLog::query()
        ->where('auditable_type', Position::class)
        ->where('action', 'created')
        ->latest('id')
        ->first();

    expect($row->tenant_id)->toBe($this->tenant->id);
    expect($row->company_id)->toBe($this->company->id);
    expect($row->actor_id)->toBe($this->admin->id);
});

it('returns 401 when called with no authenticated session', function (): void {
    $this->postJson('/api/v1/hrm/positions', validPositionPayload())->assertStatus(401);
});

it('returns 403 when the user lacks hrm.position.create permission', function (): void {
    $viewer = User::factory()->forTenant($this->tenant)->create([
        'default_company_id' => $this->company->id,
        'current_company_id' => $this->company->id,
    ]);
    $viewer->assignTenantRole($this->tenant, 'viewer');

    $this->actingAs($viewer)
        ->postJson('/api/v1/hrm/positions', validPositionPayload())
        ->assertStatus(403);
});

it('returns 422 with field-keyed errors on missing required fields', function (): void {
    $this->actingAs($this->admin);
    $response = $this->postJson('/api/v1/hrm/positions', [
        // missing code, title, status
        'description' => 'partial',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['code', 'title', 'status']);
});

it('returns 422 when code duplicates an existing code in the same company', function (): void {
    Position::factory()->forCompany($this->company)->create(['code' => 'DUP-001']);

    $this->actingAs($this->admin);
    $this->postJson('/api/v1/hrm/positions', validPositionPayload(['code' => 'DUP-001']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('code');
});

it('allows the same code in different companies within the same tenant', function (): void {
    $otherCompany = Company::factory()->forTenant($this->tenant)->create();
    Position::factory()->forCompany($otherCompany)->create(['code' => 'DUP-OK']);

    $this->actingAs($this->admin);
    $this->postJson('/api/v1/hrm/positions', validPositionPayload(['code' => 'DUP-OK']))
        ->assertStatus(Response::HTTP_CREATED);
});

it('allows re-creating with same code after the previous row was soft-deleted (partial unique index)', function (): void {
    // The partial unique index excludes deleted_at IS NOT NULL rows,
    // so the standard "delete a wrong entry, re-create with same code"
    // workflow is supported.
    $existing = Position::factory()->forCompany($this->company)->create(['code' => 'REUSED']);
    $existing->delete();

    $this->actingAs($this->admin);
    $this->postJson('/api/v1/hrm/positions', validPositionPayload(['code' => 'REUSED']))
        ->assertStatus(Response::HTTP_CREATED);
});

it('returns 422 when status is not in the enum', function (): void {
    $this->actingAs($this->admin);
    $this->postJson('/api/v1/hrm/positions', validPositionPayload(['status' => 'gibberish']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('status');
});

it('returns 422 when description exceeds 500 characters', function (): void {
    $this->actingAs($this->admin);
    $this->postJson('/api/v1/hrm/positions', validPositionPayload([
        'description' => str_repeat('x', 501),
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('description');
});
