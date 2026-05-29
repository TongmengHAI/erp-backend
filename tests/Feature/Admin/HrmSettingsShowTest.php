<?php

declare(strict_types=1);

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
});

it('returns the current company HRM settings (defaults from listener)', function (): void {
    $this->actingAs($this->admin);
    $response = $this->getJson('/api/v1/admin/hrm/settings');

    $response->assertOk();
    $response->assertJsonPath('data.company_id', $this->company->id);
    $response->assertJsonPath('data.auto_generate_employee_code', false);
    $response->assertJsonPath('data.employee_code_prefix', null);
    $response->assertJsonPath('data.default_employee_status', 'active');
});

it('returns 401 when called with no authenticated session', function (): void {
    $this->getJson('/api/v1/admin/hrm/settings')->assertStatus(401);
});

it('returns 403 when the user lacks settings.hrm.view permission', function (): void {
    $unprivileged = User::factory()->forTenant($this->tenant)->create([
        'default_company_id' => $this->company->id,
        'current_company_id' => $this->company->id,
    ]);

    $this->actingAs($unprivileged)
        ->getJson('/api/v1/admin/hrm/settings')
        ->assertStatus(403);
});

it('LOAD-BEARING: cross-tenant isolation — admin cannot view another tenant\'s settings via ?company_id=X', function (): void {
    $otherTenant = Tenant::factory()->create();
    $otherCompany = Company::factory()->forTenant($otherTenant)->create();

    $this->actingAs($this->admin)
        ->getJson("/api/v1/admin/hrm/settings?company_id={$otherCompany->id}")
        ->assertStatus(404);
});

it('LOAD-BEARING: cross-company isolation within the same tenant — admin in company X cannot view company Y\'s settings', function (): void {
    $otherCompany = Company::factory()->forTenant($this->tenant)->create();

    $this->actingAs($this->admin)
        ->getJson("/api/v1/admin/hrm/settings?company_id={$otherCompany->id}")
        ->assertStatus(404);
});

it('returns 422 when the supplied company_id is non-numeric', function (): void {
    $this->actingAs($this->admin)
        ->getJson('/api/v1/admin/hrm/settings?company_id=garbage')
        ->assertStatus(422)
        ->assertJsonValidationErrors('company_id');
});
