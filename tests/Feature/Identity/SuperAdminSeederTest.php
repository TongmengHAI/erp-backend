<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────────
// SuperAdminSeederTest — exercises Framework\SuperAdminSeeder.
//
// The seeder ships the canonical local-dev SA user with a hardcoded password.
// The environment gate is the real defense against accidental production
// invocation; password value is convenience. This test pins both:
//
//   1. Happy path (local/testing env) — creates the SA user with the
//      documented credentials.
//   2. Environment gate — throws RuntimeException when invoked outside
//      local/testing.
//   3. Idempotency — second run doesn't duplicate or crash.
// ─────────────────────────────────────────────────────────────────────────────

use App\Models\User;
use App\Support\Identity\Enums\UserType;
use Database\Seeders\Framework\SuperAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('creates the canonical local-dev super_admin user', function (): void {
    // Pest runs in the 'testing' environment by default; the seeder's
    // environment gate passes.
    (new SuperAdminSeeder)->run();

    /** @var User|null $sa */
    $sa = User::query()->where('email', 'superadmin@myerp.local')->first();

    expect($sa)->not->toBeNull();
    expect($sa->name)->toBe('Super Admin');
    expect($sa->type)->toBe(UserType::SuperAdmin);
    expect($sa->tenant_id)->toBeNull();
    expect($sa->current_tenant_id)->toBeNull();
    expect($sa->default_company_id)->toBeNull();
    expect($sa->current_company_id)->toBeNull();
    expect(Hash::check('superadmin', $sa->password))->toBeTrue();
});

it('is idempotent — re-running does not duplicate the SA user', function (): void {
    (new SuperAdminSeeder)->run();
    (new SuperAdminSeeder)->run();

    $count = User::query()->where('email', 'superadmin@myerp.local')->count();
    expect($count)->toBe(1);
});

it('throws RuntimeException when invoked outside local/testing environment', function (): void {
    // Force the app environment to 'production' for the duration of this
    // test. The seeder's `app()->environment(['local', 'testing'])` check
    // must reject the run.
    $original = app()->environment();
    app()->detectEnvironment(fn () => 'production');

    try {
        expect(fn () => (new SuperAdminSeeder)->run())
            ->toThrow(RuntimeException::class, 'SuperAdminSeeder is dev-only');
    } finally {
        // Restore — Pest test isolation otherwise leaks the env override.
        app()->detectEnvironment(fn () => $original);
    }

    expect(User::query()->where('email', 'superadmin@myerp.local')->exists())->toBeFalse();
});
