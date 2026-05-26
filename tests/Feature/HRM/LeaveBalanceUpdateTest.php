<?php

declare(strict_types=1);

use App\Domain\HRM\Models\Employee;
use App\Domain\HRM\Models\LeaveBalance;
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

    $this->employee = Employee::factory()->forCompany($this->company)->create();
    $this->balance = LeaveBalance::factory()->forEmployee($this->employee)->annual()->create([
        'period_year' => 2026,
        'allocated_days' => 14.0,
    ]);
});

it('updates allocated_days and returns the refreshed resource', function (): void {
    $response = $this->actingAs($this->admin)
        ->patchJson("/api/v1/hrm/leave-balances/{$this->balance->id}", [
            'allocated_days' => 16.0,
        ])
        ->assertOk();

    // JSON encodes 16.0 as 16 (no JSON_PRESERVE_ZERO_FRACTION).
    // Cast through float for shape-stable comparison.
    expect((float) $response->json('data.allocated_days'))->toBe(16.0);
    expect((float) $response->json('data.remaining_days'))->toBe(16.0);
});

it('returns 401 when called with no authenticated session', function (): void {
    $this->patchJson("/api/v1/hrm/leave-balances/{$this->balance->id}", [
        'allocated_days' => 16.0,
    ])->assertStatus(401);
});

it('returns 403 when the user lacks hrm.leave_balance.update permission', function (): void {
    $viewer = User::factory()->forTenant($this->tenant)->create([
        'default_company_id' => $this->company->id,
        'current_company_id' => $this->company->id,
    ]);
    $viewer->assignTenantRole($this->tenant, 'viewer');

    $this->actingAs($viewer)
        ->patchJson("/api/v1/hrm/leave-balances/{$this->balance->id}", ['allocated_days' => 16.0])
        ->assertStatus(403);
});

it('returns 422 when allocated_days is negative', function (): void {
    $this->actingAs($this->admin)
        ->patchJson("/api/v1/hrm/leave-balances/{$this->balance->id}", ['allocated_days' => -2.0])
        ->assertStatus(422)
        ->assertJsonValidationErrors('allocated_days');
});

it('returns 404 cross-tenant — admin cannot update a balance in another tenant', function (): void {
    $otherTenant = Tenant::factory()->create();
    $otherCompany = Company::factory()->forTenant($otherTenant)->create();
    $otherEmployee = Employee::factory()->forCompany($otherCompany)->create();
    $foreign = LeaveBalance::factory()->forEmployee($otherEmployee)->annual()->create(['period_year' => 2026]);

    $this->actingAs($this->admin)
        ->patchJson("/api/v1/hrm/leave-balances/{$foreign->id}", ['allocated_days' => 14.0])
        ->assertStatus(404);
});

it('returns 404 cross-company within the same tenant', function (): void {
    $otherCompany = Company::factory()->forTenant($this->tenant)->create();
    $otherEmployee = Employee::factory()->forCompany($otherCompany)->create();
    $foreign = LeaveBalance::factory()->forEmployee($otherEmployee)->annual()->create(['period_year' => 2026]);

    $this->actingAs($this->admin)
        ->patchJson("/api/v1/hrm/leave-balances/{$foreign->id}", ['allocated_days' => 14.0])
        ->assertStatus(404);
});
