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
});

it('soft-deletes a balance and returns 204', function (): void {
    $balance = LeaveBalance::factory()->forEmployee($this->employee)->annual()->create(['period_year' => 2026]);

    $this->actingAs($this->admin)
        ->deleteJson("/api/v1/hrm/leave-balances/{$balance->id}")
        ->assertStatus(204);

    expect(LeaveBalance::query()->find($balance->id))->toBeNull();
    expect(LeaveBalance::query()->withTrashed()->find($balance->id))->not->toBeNull();
});

it('returns 401 when called with no authenticated session', function (): void {
    $balance = LeaveBalance::factory()->forEmployee($this->employee)->annual()->create(['period_year' => 2026]);
    $this->deleteJson("/api/v1/hrm/leave-balances/{$balance->id}")->assertStatus(401);
});

it('returns 403 when the user lacks hrm.leave_balance.delete permission', function (): void {
    $balance = LeaveBalance::factory()->forEmployee($this->employee)->annual()->create(['period_year' => 2026]);
    $viewer = User::factory()->forTenant($this->tenant)->create([
        'default_company_id' => $this->company->id,
        'current_company_id' => $this->company->id,
    ]);
    $viewer->assignTenantRole($this->tenant, 'viewer');

    $this->actingAs($viewer)
        ->deleteJson("/api/v1/hrm/leave-balances/{$balance->id}")
        ->assertStatus(403);
});

it('returns 404 for a cross-tenant balance id', function (): void {
    $otherTenant = Tenant::factory()->create();
    $otherCompany = Company::factory()->forTenant($otherTenant)->create();
    $otherEmployee = Employee::factory()->forCompany($otherCompany)->create();
    $foreign = LeaveBalance::factory()->forEmployee($otherEmployee)->annual()->create(['period_year' => 2026]);

    $this->actingAs($this->admin)
        ->deleteJson("/api/v1/hrm/leave-balances/{$foreign->id}")
        ->assertStatus(404);
});

it('returns 404 on a second DELETE (idempotency)', function (): void {
    $balance = LeaveBalance::factory()->forEmployee($this->employee)->annual()->create(['period_year' => 2026]);

    $this->actingAs($this->admin);
    $this->deleteJson("/api/v1/hrm/leave-balances/{$balance->id}")->assertStatus(204);
    $this->deleteJson("/api/v1/hrm/leave-balances/{$balance->id}")->assertStatus(404);
});
