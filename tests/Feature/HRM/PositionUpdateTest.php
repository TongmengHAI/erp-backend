<?php

declare(strict_types=1);

use App\Domain\HRM\Models\Position;
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

    $this->position = Position::factory()->forCompany($this->company)->create([
        'code' => 'P-EDIT',
        'title' => 'Original Title',
    ]);
});

it('updates the position and returns the refreshed resource', function (): void {
    $this->actingAs($this->admin);
    $response = $this->patchJson("/api/v1/hrm/positions/{$this->position->id}", [
        'title' => 'Updated Title',
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.title', 'Updated Title');
    $response->assertJsonPath('data.code', 'P-EDIT');
});

it('returns 401 when called with no authenticated session', function (): void {
    $this->patchJson("/api/v1/hrm/positions/{$this->position->id}", ['title' => 'X'])
        ->assertStatus(401);
});

it('returns 403 when the user lacks hrm.position.update permission', function (): void {
    $viewer = User::factory()->forTenant($this->tenant)->create([
        'default_company_id' => $this->company->id,
        'current_company_id' => $this->company->id,
    ]);
    $viewer->assignTenantRole($this->tenant, 'viewer');

    $this->actingAs($viewer)
        ->patchJson("/api/v1/hrm/positions/{$this->position->id}", ['title' => 'X'])
        ->assertStatus(403);
});

it('returns 422 when status is not in the enum', function (): void {
    $this->actingAs($this->admin)
        ->patchJson("/api/v1/hrm/positions/{$this->position->id}", ['status' => 'gibberish'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('status');
});

it('returns 422 when changing code to one already in use within the same company', function (): void {
    Position::factory()->forCompany($this->company)->create(['code' => 'P-TAKEN']);

    $this->actingAs($this->admin)
        ->patchJson("/api/v1/hrm/positions/{$this->position->id}", ['code' => 'P-TAKEN'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('code');
});

it('allows keeping the same code on update (ignore-self in unique check)', function (): void {
    $this->actingAs($this->admin)
        ->patchJson("/api/v1/hrm/positions/{$this->position->id}", [
            'code' => 'P-EDIT',
            'title' => 'Same Code OK',
        ])
        ->assertOk();
});

it('returns 404 cross-tenant', function (): void {
    $otherTenant = Tenant::factory()->create();
    $otherCompany = Company::factory()->forTenant($otherTenant)->create();
    $other = Position::factory()->forCompany($otherCompany)->create();

    $this->actingAs($this->admin)
        ->patchJson("/api/v1/hrm/positions/{$other->id}", ['title' => 'hijack'])
        ->assertStatus(404);
});

it('returns 404 cross-company within the same tenant', function (): void {
    $otherCompany = Company::factory()->forTenant($this->tenant)->create();
    $other = Position::factory()->forCompany($otherCompany)->create();

    $this->actingAs($this->admin)
        ->patchJson("/api/v1/hrm/positions/{$other->id}", ['title' => 'hijack'])
        ->assertStatus(404);
});
