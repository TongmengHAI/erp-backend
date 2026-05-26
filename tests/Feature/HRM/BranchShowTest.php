<?php

declare(strict_types=1);

use App\Domain\HRM\Models\Branch;
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

    $this->branch = Branch::factory()->forCompany($this->company)->create([
        'code' => 'B-PNH-HQ',
        'name' => 'Phnom Penh HQ',
        'city' => 'Phnom Penh',
        'country_code' => 'KH',
    ]);
});

it('returns 200 with the full resource shape including employees_count and location fields', function (): void {
    $this->actingAs($this->admin);
    $response = $this->getJson("/api/v1/hrm/branches/{$this->branch->id}");

    $response->assertOk();
    $response->assertJsonPath('data.id', $this->branch->id);
    $response->assertJsonPath('data.code', 'B-PNH-HQ');
    $response->assertJsonPath('data.name', 'Phnom Penh HQ');
    $response->assertJsonPath('data.city', 'Phnom Penh');
    $response->assertJsonPath('data.country_code', 'KH');
    $response->assertJsonStructure([
        'data' => [
            'id', 'code', 'name', 'description',
            'address', 'city', 'country_code', 'phone',
            'status', 'employees_count', 'created_at', 'updated_at',
        ],
    ]);
});

it('employees_count reflects only same-(tenant, company) employees at this branch', function (): void {
    Employee::factory()->forCompany($this->company)->forBranch($this->branch)->count(3)->create();

    $this->actingAs($this->admin);
    $response = $this->getJson("/api/v1/hrm/branches/{$this->branch->id}");

    $response->assertOk();
    $response->assertJsonPath('data.employees_count', 3);
});

it('returns 401 when called with no authenticated session', function (): void {
    $this->getJson("/api/v1/hrm/branches/{$this->branch->id}")->assertStatus(401);
});

it('returns 403 when the user lacks hrm.branch.view permission', function (): void {
    $unprivileged = User::factory()->forTenant($this->tenant)->create([
        'default_company_id' => $this->company->id,
        'current_company_id' => $this->company->id,
    ]);

    $this->actingAs($unprivileged)
        ->getJson("/api/v1/hrm/branches/{$this->branch->id}")
        ->assertStatus(403);
});

it('returns 404 for a cross-tenant branch id', function (): void {
    $otherTenant = Tenant::factory()->create();
    $otherCompany = Company::factory()->forTenant($otherTenant)->create();
    $foreign = Branch::factory()->forCompany($otherCompany)->create();

    $this->actingAs($this->admin)
        ->getJson("/api/v1/hrm/branches/{$foreign->id}")
        ->assertStatus(404);
});

it('returns 404 for a cross-company branch id within the same tenant', function (): void {
    $otherCompany = Company::factory()->forTenant($this->tenant)->create();
    $foreign = Branch::factory()->forCompany($otherCompany)->create();

    $this->actingAs($this->admin)
        ->getJson("/api/v1/hrm/branches/{$foreign->id}")
        ->assertStatus(404);
});
