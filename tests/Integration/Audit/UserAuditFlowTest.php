<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Support\Audit\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('updating a User produces an audit row with correct before/after, actor, and tenant_id', function (): void {
    $tenant = Tenant::factory()->create();
    $actor = User::factory()->forTenant($tenant)->create();
    $target = User::factory()->forTenant($tenant)->create(['name' => 'Original Name']);

    $this->actingAs($actor);

    $target->update(['name' => 'Updated Name']);

    $row = AuditLog::query()
        ->where('auditable_type', User::class)
        ->where('auditable_id', $target->id)
        ->where('action', 'updated')
        ->latest('id')
        ->first();

    expect($row)->not->toBeNull();
    expect($row->tenant_id)->toBe($tenant->id);   // user.tenant_id propagated to audit row
    expect($row->actor_id)->toBe($actor->id);     // captured from AuditContext
    expect($row->actor_type)->toBe(User::class);
    expect($row->before)->toEqual(['name' => 'Original Name']);
    expect($row->after)->toEqual(['name' => 'Updated Name']);
});

it('creating a Tenant produces an audit row with action=created and tenant_id=null (Tenant is its own scope)', function (): void {
    $tenant = Tenant::factory()->create(['name' => 'New Tenant']);

    $row = AuditLog::query()
        ->where('auditable_type', Tenant::class)
        ->where('auditable_id', $tenant->id)
        ->where('action', 'created')
        ->latest('id')
        ->first();

    expect($row)->not->toBeNull();
    expect($row->tenant_id)->toBeNull(); // Tenant has no tenant_id column → falls through; no TenantContext outside a request → null
    expect($row->after)->toHaveKey('name');
    expect($row->after['name'])->toBe('New Tenant');
    expect($row->before)->toBeNull();
});

it('rolling back the parent transaction does NOT persist the audit row (atomicity)', function (): void {
    $countBefore = AuditLog::query()->count();

    try {
        DB::transaction(function (): void {
            Tenant::factory()->create();
            // Tenant create just fired an audit INSERT inside this savepoint.
            // Throwing aborts the savepoint, rolling back BOTH the tenant
            // row AND the audit row.
            throw new RuntimeException('intentional rollback');
        });
    } catch (RuntimeException) {
        // expected — propagated by DB::transaction after rollback
    }

    $countAfter = AuditLog::query()->count();
    expect($countAfter)->toBe($countBefore);
});
