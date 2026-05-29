<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Identity\Enums\UserType;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

/**
 * Create a vendor-side Super Admin user.
 *
 *   php artisan super-admin:create --email=ops@example.com --name="Ops Lead"
 *
 * Password resolution (first match wins):
 *   1. --password flag (NOT recommended for production — visible in shell history)
 *   2. SUPER_ADMIN_PASSWORD env var (set in the deploy shell, unset after)
 *   3. Interactive STDIN prompt via secret() (default; recommended)
 *
 * The plaintext password is NEVER logged. The hashed value is written to
 * users.password; the plaintext value exists only for the duration of this
 * command invocation (in the secret() prompt buffer or env var) and is
 * dropped after Hash::make.
 *
 * Use the SuperAdminSeeder for local development convenience; this command
 * is the production path.
 */
class SuperAdminCreate extends Command
{
    protected $signature = 'super-admin:create
        {--email= : Email address for the new Super Admin (required)}
        {--name= : Display name for the new Super Admin (required)}
        {--password= : Password (discouraged; prefer SUPER_ADMIN_PASSWORD env or interactive prompt)}';

    protected $description = 'Create a vendor-side Super Admin user.';

    public function handle(): int
    {
        $email = $this->option('email');
        $name = $this->option('name');

        if (! is_string($email) || $email === '' || ! is_string($name) || $name === '') {
            $this->error('Both --email and --name are required.');

            return self::FAILURE;
        }

        $emailValidator = Validator::make(
            ['email' => $email],
            ['email' => ['required', 'email', 'max:255']],
        );
        if ($emailValidator->fails()) {
            $this->error(sprintf('Invalid email: %s', $emailValidator->errors()->first('email')));

            return self::FAILURE;
        }

        if (User::query()->where('email', $email)->exists()) {
            $this->error(sprintf('A user with email "%s" already exists.', $email));

            return self::FAILURE;
        }

        $password = $this->resolvePassword();
        if ($password === null) {
            // resolvePassword already printed the reason.
            return self::FAILURE;
        }

        DB::transaction(function () use ($email, $name, $password): void {
            User::query()->create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make($password),
                'email_verified_at' => now(),
                'type' => UserType::SuperAdmin,
                // All four tenant/company FKs deliberately omitted; the
                // composite DB CHECK enforces they stay NULL.
            ]);
        });

        // Plaintext $password drops out of scope here. NEVER write it to
        // Log:: / report() / Telescope-readable output. The success line
        // names the user (email + name) only — no secret leakage.
        $this->info(sprintf('Super Admin created: %s (%s).', $name, $email));

        return self::SUCCESS;
    }

    /**
     * Resolve the password from --password, env var, or interactive prompt.
     * Returns null if the user cannot supply a password (e.g. running
     * non-interactively without --password or env var).
     */
    private function resolvePassword(): ?string
    {
        $flag = $this->option('password');
        if (is_string($flag) && $flag !== '') {
            return $flag;
        }

        $env = getenv('SUPER_ADMIN_PASSWORD');
        if (is_string($env) && $env !== '') {
            return $env;
        }

        if (! $this->input->isInteractive()) {
            $this->error(
                'Non-interactive shell: provide --password or SUPER_ADMIN_PASSWORD env var.',
            );

            return null;
        }

        $first = $this->secret('Password');
        if (! is_string($first) || $first === '') {
            $this->error('Password cannot be empty.');

            return null;
        }

        $confirm = $this->secret('Confirm password');
        if ($first !== $confirm) {
            $this->error('Passwords do not match.');

            return null;
        }

        return $first;
    }
}
