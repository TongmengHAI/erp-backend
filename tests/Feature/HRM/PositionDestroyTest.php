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
});

it('soft-deletes a position and returns 204', function (): void {
    $position = Position::factory()->forCompany($this->company)->create();

    $this->actingAs($this->admin)
        ->deleteJson("/api/v1/hrm/positions/{$position->id}")
        ->assertStatus(204);

    expect(Position::query()->find($position->id))->toBeNull();
    expect(Position::query()->withTrashed()->find($position->id))->not->toBeNull();
});

it('returns 401 when called with no authenticated session', function (): void {
    $position = Position::factory()->forCompany($this->company)->create();
    $this->deleteJson("/api/v1/hrm/positions/{$position->id}")->assertStatus(401);
});

it('returns 403 when the user lacks hrm.position.delete permission', function (): void {
    $position = Position::factory()->forCompany($this->company)->create();

    $viewer = User::factory()->forTenant($this->tenant)->create([
        'default_company_id' => $this->company->id,
        'current_company_id' => $this->company->id,
    ]);
    $viewer->assignTenantRole($this->tenant, 'viewer');

    $this->actingAs($viewer)
        ->deleteJson("/api/v1/hrm/positions/{$position->id}")
        ->assertStatus(403);
});

it('returns 404 for a cross-tenant position id', function (): void {
    $otherTenant = Tenant::factory()->create();
    $otherCompany = Company::factory()->forTenant($otherTenant)->create();
    $foreign = Position::factory()->forCompany($otherCompany)->create();

    $this->actingAs($this->admin)
        ->deleteJson("/api/v1/hrm/positions/{$foreign->id}")
        ->assertStatus(404);
});

it('returns 404 on a second DELETE (soft-deleted rows are invisible to route-model binding)', function (): void {
    $position = Position::factory()->forCompany($this->company)->create();

    $this->actingAs($this->admin);
    $this->deleteJson("/api/v1/hrm/positions/{$position->id}")->assertStatus(204);
    $this->deleteJson("/api/v1/hrm/positions/{$position->id}")->assertStatus(404);
});
