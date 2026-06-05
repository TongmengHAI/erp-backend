<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────────
// UsersPermissionSeedTest — Phase 2A Session 1.
//
// Pins the locked decision: in Phase 2A, all five users.* permissions
// (view, invite, update, disable, deactivate) are granted to the
// tenant_admin role only. Every other role gets ZERO users.*
// permissions. Subset-by-role lands in Phase 2B alongside the custom-
// role editor — until then, the only way to manage users is via
// tenant_admin authority.
//
// Forward-compatible loop: when Phase 2B (or any later slice) adds a
// new role, this test automatically catches it accidentally receiving
// users.* permissions. The diagnostic message names which role and
// which permission so a future failure is immediately readable — same
// discipline as §10.8's frozen-const test diagnostic.
// ─────────────────────────────────────────────────────────────────────────────

use Database\Seeders\Framework\DefaultPermissionsSeeder;
use Database\Seeders\Framework\DefaultRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(DefaultPermissionsSeeder::class);
    $this->seed(DefaultRolesSeeder::class);
});

it('LOAD-BEARING: only tenant_admin receives users.* permissions; all other roles get zero', function (): void {
    $usersPerms = [
        'users.view',
        'users.invite',
        'users.update',
        'users.disable',
        'users.deactivate',
    ];

    // tenant_admin gets every users.* permission. Pest's diagnostic
    // message goes as the second arg to expect(), NOT to toContain().
    $tenantAdmin = Role::findByName('tenant_admin', 'web');
    $tenantAdminPerms = $tenantAdmin->permissions->pluck('name')->all();
    foreach ($usersPerms as $perm) {
        expect(
            $tenantAdminPerms,
            "tenant_admin should have '{$perm}' in Phase 2A but does not."
        )->toContain($perm);
    }

    // Every NON-admin role gets ZERO users.* permissions. Loop catches
    // future roles (manager, payroll_admin, anything) that accidentally
    // inherit users.* — the diagnostic names the role + permission so
    // the failure is immediately readable.
    Role::query()->where('name', '!=', 'tenant_admin')->get()->each(
        function (Role $role) use ($usersPerms): void {
            $overlap = $role->permissions->pluck('name')->intersect($usersPerms)->all();
            expect(
                $overlap,
                "Role '{$role->name}' should have zero users.* permissions in Phase 2A but has: "
                    .implode(', ', $overlap)
            )->toBe([]);
        }
    );
});
