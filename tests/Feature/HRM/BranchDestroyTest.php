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
});

it('soft-deletes a branch and returns 204', function (): void {
    $branch = Branch::factory()->forCompany($this->company)->create();

    $this->actingAs($this->admin)
        ->deleteJson("/api/v1/hrm/branches/{$branch->id}")
        ->assertStatus(204);

    expect(Branch::query()->find($branch->id))->toBeNull();
    expect(Branch::query()->withTrashed()->find($branch->id))->not->toBeNull();
});

it('returns 401 when called with no authenticated session', function (): void {
    $branch = Branch::factory()->forCompany($this->company)->create();
    $this->deleteJson("/api/v1/hrm/branches/{$branch->id}")->assertStatus(401);
});

it('returns 403 when the user lacks hrm.branch.delete permission', function (): void {
    $branch = Branch::factory()->forCompany($this->company)->create();

    $unprivileged = User::factory()->forTenant($this->tenant)->create([
        'default_company_id' => $this->company->id,
        'current_company_id' => $this->company->id,
    ]);

    $this->actingAs($unprivileged)
        ->deleteJson("/api/v1/hrm/branches/{$branch->id}")
        ->assertStatus(403);
});

it('returns 404 for a cross-tenant branch id', function (): void {
    $otherTenant = Tenant::factory()->create();
    $otherCompany = Company::factory()->forTenant($otherTenant)->create();
    $foreign = Branch::factory()->forCompany($otherCompany)->create();

    $this->actingAs($this->admin)
        ->deleteJson("/api/v1/hrm/branches/{$foreign->id}")
        ->assertStatus(404);
});

it('returns 404 on a second DELETE (idempotency)', function (): void {
    $branch = Branch::factory()->forCompany($this->company)->create();

    $this->actingAs($this->admin);
    $this->deleteJson("/api/v1/hrm/branches/{$branch->id}")->assertStatus(204);
    $this->deleteJson("/api/v1/hrm/branches/{$branch->id}")->assertStatus(404);
});
