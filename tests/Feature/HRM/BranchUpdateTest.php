<?php

declare(strict_types=1);

use App\Domain\HRM\Models\Branch;
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

    $this->branch = Branch::factory()->forCompany($this->company)->create([
        'code' => 'B-EDIT',
        'name' => 'Original Branch',
    ]);
});

it('PATCHes name and returns 200 with the refreshed resource', function (): void {
    $this->actingAs($this->admin);
    $response = $this->patchJson("/api/v1/hrm/branches/{$this->branch->id}", [
        'name' => 'Updated Branch',
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.name', 'Updated Branch');
    $response->assertJsonPath('data.code', 'B-EDIT'); // unchanged
});

it('returns 401 when called with no authenticated session', function (): void {
    $this->patchJson("/api/v1/hrm/branches/{$this->branch->id}", ['name' => 'x'])
        ->assertStatus(401);
});

it('returns 403 when the user lacks hrm.branch.update permission', function (): void {
    $unprivileged = User::factory()->forTenant($this->tenant)->create([
        'default_company_id' => $this->company->id,
        'current_company_id' => $this->company->id,
    ]);

    $this->actingAs($unprivileged)
        ->patchJson("/api/v1/hrm/branches/{$this->branch->id}", ['name' => 'x'])
        ->assertStatus(403);
});

it('returns 422 when changing code to one already in use within the same company', function (): void {
    Branch::factory()->forCompany($this->company)->create(['code' => 'TAKEN']);

    $this->actingAs($this->admin)
        ->patchJson("/api/v1/hrm/branches/{$this->branch->id}", ['code' => 'TAKEN'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('code');
});

it('allows keeping the same code on update (ignore-self in unique check)', function (): void {
    $this->actingAs($this->admin);
    $this->patchJson("/api/v1/hrm/branches/{$this->branch->id}", [
        'code' => 'B-EDIT',
        'name' => 'Same Code OK',
    ])->assertOk();
});

it('returns 422 on country_code PATCH that violates the regex (lowercase)', function (): void {
    $this->actingAs($this->admin)
        ->patchJson("/api/v1/hrm/branches/{$this->branch->id}", ['country_code' => 'us'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('country_code');
});

it('returns 404 for a cross-tenant branch id', function (): void {
    $otherTenant = Tenant::factory()->create();
    $otherCompany = Company::factory()->forTenant($otherTenant)->create();
    $foreign = Branch::factory()->forCompany($otherCompany)->create();

    $this->actingAs($this->admin)
        ->patchJson("/api/v1/hrm/branches/{$foreign->id}", ['name' => 'hijack'])
        ->assertStatus(404);
});
