<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────────
// SuperAdminCreateCommandTest — exercises `php artisan super-admin:create`.
//
// Per Session 1 plan tightening #3: the plaintext password is NEVER persisted
// to any log, audit row, or stored field other than the BCrypt hash on
// users.password. The "no plaintext leakage" assertion lives at the end of
// the happy-path test by inspecting users.password (hashed, not plaintext).
//
// The interactive STDIN prompt path is NOT covered here — Symfony Console
// secret() can't be driven from Pest without input-stream gymnastics, and
// the --password and SUPER_ADMIN_PASSWORD env paths exercise the same
// resolvePassword + Hash::make + DB::transaction code.
// ─────────────────────────────────────────────────────────────────────────────

use App\Models\User;
use App\Support\Identity\Enums\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('creates a super_admin user via --email --name --password', function (): void {
    $exit = $this->artisan('super-admin:create', [
        '--email' => 'ops@example.test',
        '--name' => 'Ops Lead',
        '--password' => 'CorrectHorseBatteryStaple',
    ])->expectsOutputToContain('Super Admin created')
        ->run();

    expect($exit)->toBe(0);

    /** @var User|null $created */
    $created = User::query()->where('email', 'ops@example.test')->first();

    expect($created)->not->toBeNull();
    expect($created->name)->toBe('Ops Lead');
    expect($created->type)->toBe(UserType::SuperAdmin);
    expect($created->tenant_id)->toBeNull();
    expect($created->current_tenant_id)->toBeNull();
    expect($created->default_company_id)->toBeNull();
    expect($created->current_company_id)->toBeNull();

    // Password is BCrypt-hashed; plaintext does NOT appear anywhere in
    // the stored column. Verifying via Hash::check is the canonical way
    // to confirm the hash matches without exposing the plaintext form.
    expect($created->password)->not->toBe('CorrectHorseBatteryStaple');
    expect(Hash::check('CorrectHorseBatteryStaple', $created->password))->toBeTrue();
});

it('fails with non-zero exit when --email is missing', function (): void {
    $exit = $this->artisan('super-admin:create', [
        '--name' => 'No Email',
    ])->expectsOutputToContain('--email and --name are required')
        ->run();

    expect($exit)->not->toBe(0);
});

it('fails when --email is malformed', function (): void {
    $exit = $this->artisan('super-admin:create', [
        '--email' => 'not-an-email',
        '--name' => 'Bad Email',
        '--password' => 'whatever',
    ])->expectsOutputToContain('Invalid email')
        ->run();

    expect($exit)->not->toBe(0);
});

it('fails when the email already exists', function (): void {
    User::factory()->superAdmin()->create([
        'email' => 'taken@example.test',
        'name' => 'Already Here',
    ]);

    $exit = $this->artisan('super-admin:create', [
        '--email' => 'taken@example.test',
        '--name' => 'Collision',
        '--password' => 'whatever',
    ])->expectsOutputToContain('already exists')
        ->run();

    expect($exit)->not->toBe(0);

    // Verify the existing user wasn't modified.
    /** @var User $existing */
    $existing = User::query()->where('email', 'taken@example.test')->first();
    expect($existing->name)->toBe('Already Here');
});

it('reads password from SUPER_ADMIN_PASSWORD env var when --password not given', function (): void {
    putenv('SUPER_ADMIN_PASSWORD=FromEnvVar123!');

    try {
        $exit = $this->artisan('super-admin:create', [
            '--email' => 'env@example.test',
            '--name' => 'Env Path',
        ])->expectsOutputToContain('Super Admin created')
            ->run();

        expect($exit)->toBe(0);

        /** @var User|null $created */
        $created = User::query()->where('email', 'env@example.test')->first();
        expect($created)->not->toBeNull();
        expect(Hash::check('FromEnvVar123!', $created->password))->toBeTrue();
    } finally {
        putenv('SUPER_ADMIN_PASSWORD');
    }
});
