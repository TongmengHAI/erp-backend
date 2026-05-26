<?php

declare(strict_types=1);

use App\Domain\HRM\Models\Branch;
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

function validBranchPayload(array $overrides = []): array
{
    return array_merge([
        'code' => 'B-NEW1',
        'name' => 'New Branch',
        'description' => 'A new branch.',
        'address' => 'Street 240',
        'city' => 'Phnom Penh',
        'country_code' => 'KH',
        'phone' => '+855 23 123 456',
        'status' => 'active',
    ], $overrides);
}

it('creates a branch and returns 201 with the full resource', function (): void {
    $this->actingAs($this->admin);
    $response = $this->postJson('/api/v1/hrm/branches', validBranchPayload());

    $response->assertStatus(Response::HTTP_CREATED);
    $response->assertJsonPath('data.code', 'B-NEW1');
    $response->assertJsonPath('data.name', 'New Branch');
    $response->assertJsonPath('data.city', 'Phnom Penh');
    $response->assertJsonPath('data.country_code', 'KH');
    $response->assertJsonPath('data.status', 'active');

    $row = Branch::query()->where('code', 'B-NEW1')->firstOrFail();
    expect($row->tenant_id)->toBe($this->tenant->id);
    expect($row->company_id)->toBe($this->company->id);
});

it('writes an audit row with non-null tenant_id + company_id + actor_id', function (): void {
    $this->actingAs($this->admin);
    $this->postJson('/api/v1/hrm/branches', validBranchPayload());

    $row = AuditLog::query()
        ->where('auditable_type', Branch::class)
        ->where('action', 'created')
        ->latest('id')
        ->first();

    expect($row->tenant_id)->toBe($this->tenant->id);
    expect($row->company_id)->toBe($this->company->id);
    expect($row->actor_id)->toBe($this->admin->id);
});

it('returns 401 when called with no authenticated session', function (): void {
    $this->postJson('/api/v1/hrm/branches', validBranchPayload())->assertStatus(401);
});

it('returns 403 when the user lacks hrm.branch.create permission', function (): void {
    $viewer = User::factory()->forTenant($this->tenant)->create([
        'default_company_id' => $this->company->id,
        'current_company_id' => $this->company->id,
    ]);
    $viewer->assignTenantRole($this->tenant, 'viewer');

    $this->actingAs($viewer)
        ->postJson('/api/v1/hrm/branches', validBranchPayload())
        ->assertStatus(403);
});

it('returns 422 with field-keyed errors on missing required fields', function (): void {
    $this->actingAs($this->admin);
    $response = $this->postJson('/api/v1/hrm/branches', [
        // missing code, name, status
        'city' => 'partial',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['code', 'name', 'status']);
});

it('returns 422 when code duplicates an existing code in the same company', function (): void {
    Branch::factory()->forCompany($this->company)->create(['code' => 'DUP-001']);

    $this->actingAs($this->admin);
    $this->postJson('/api/v1/hrm/branches', validBranchPayload(['code' => 'DUP-001']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('code');
});

it('allows the same code in different companies within the same tenant', function (): void {
    $otherCompany = Company::factory()->forTenant($this->tenant)->create();
    Branch::factory()->forCompany($otherCompany)->create(['code' => 'DUP-OK']);

    $this->actingAs($this->admin);
    $this->postJson('/api/v1/hrm/branches', validBranchPayload(['code' => 'DUP-OK']))
        ->assertStatus(Response::HTTP_CREATED);
});

it('allows re-creating with same code after the previous row was soft-deleted', function (): void {
    $existing = Branch::factory()->forCompany($this->company)->create(['code' => 'REUSED']);
    $existing->delete();

    $this->actingAs($this->admin);
    $this->postJson('/api/v1/hrm/branches', validBranchPayload(['code' => 'REUSED']))
        ->assertStatus(Response::HTTP_CREATED);
});

it('accepts null physical-location fields (newly-created branches without address yet)', function (): void {
    $this->actingAs($this->admin);
    $response = $this->postJson('/api/v1/hrm/branches', validBranchPayload([
        'address' => null,
        'city' => null,
        'country_code' => null,
        'phone' => null,
    ]));

    $response->assertStatus(Response::HTTP_CREATED);
    $response->assertJsonPath('data.address', null);
    $response->assertJsonPath('data.country_code', null);
});

// ─── LOAD-BEARING country_code regex tests ──────────────────────────────────

it('LOAD-BEARING: returns 422 when country_code is lowercase (regex requires uppercase)', function (): void {
    $this->actingAs($this->admin);
    $this->postJson('/api/v1/hrm/branches', validBranchPayload([
        'country_code' => 'kh',
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('country_code');
});

it('LOAD-BEARING: returns 422 when country_code has digits (regex rejects non-alpha)', function (): void {
    $this->actingAs($this->admin);
    $this->postJson('/api/v1/hrm/branches', validBranchPayload([
        'country_code' => 'K1',
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('country_code');
});

it('LOAD-BEARING: returns 422 when country_code is more than 2 chars (regex bounds length exactly)', function (): void {
    $this->actingAs($this->admin);
    $this->postJson('/api/v1/hrm/branches', validBranchPayload([
        'country_code' => 'KHM',
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('country_code');
});

it('LOAD-BEARING: returns 422 when country_code is 1 char (regex bounds length exactly)', function (): void {
    $this->actingAs($this->admin);
    $this->postJson('/api/v1/hrm/branches', validBranchPayload([
        'country_code' => 'K',
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('country_code');
});

it('returns 422 when status is not in the enum', function (): void {
    $this->actingAs($this->admin);
    $this->postJson('/api/v1/hrm/branches', validBranchPayload(['status' => 'gibberish']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('status');
});

it('returns 422 when description exceeds 500 characters', function (): void {
    $this->actingAs($this->admin);
    $this->postJson('/api/v1/hrm/branches', validBranchPayload([
        'description' => str_repeat('x', 501),
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('description');
});

it('returns 422 when address exceeds 500 characters', function (): void {
    $this->actingAs($this->admin);
    $this->postJson('/api/v1/hrm/branches', validBranchPayload([
        'address' => str_repeat('x', 501),
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('address');
});

it('returns 422 when city exceeds 100 characters', function (): void {
    $this->actingAs($this->admin);
    $this->postJson('/api/v1/hrm/branches', validBranchPayload([
        'city' => str_repeat('x', 101),
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('city');
});

it('returns 422 when phone exceeds 32 characters', function (): void {
    $this->actingAs($this->admin);
    $this->postJson('/api/v1/hrm/branches', validBranchPayload([
        'phone' => str_repeat('1', 33),
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('phone');
});
