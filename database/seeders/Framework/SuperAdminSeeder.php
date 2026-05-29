<?php

declare(strict_types=1);

namespace Database\Seeders\Framework;

use App\Models\User;
use App\Support\Identity\Enums\UserType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Seeds the canonical local-development Super Admin user.
 *
 * DEV-ONLY: This seeder is hardcoded with a fixed password for local
 * development convenience. NEVER deploy this seeder to staging/production —
 * the environment gate below throws RuntimeException if invoked outside
 * local/testing. For non-dev environments, use:
 *
 *     php artisan super-admin:create --email=… --name=…
 *
 * (which prompts for a password via STDIN or reads SUPER_ADMIN_PASSWORD).
 *
 * The seeded SA user has:
 *   - email:    superadmin@myerp.local
 *   - password: 'superadmin'  (BCrypt-hashed at write time)
 *   - type:     UserType::SuperAdmin
 *   - all four tenant/company FK columns NULL (composite DB CHECK enforces)
 *
 * Wired into DatabaseSeeder. Idempotent — uses firstOrCreate on email so
 * re-running db:seed doesn't duplicate.
 */
class SuperAdminSeeder extends Seeder
{
    private const DEV_EMAIL = 'superadmin@myerp.local';

    private const DEV_PASSWORD = 'superadmin';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException(
                'SuperAdminSeeder is dev-only. Use `php artisan super-admin:create` in non-local/testing environments.',
            );
        }

        User::query()->firstOrCreate(
            ['email' => self::DEV_EMAIL],
            [
                'name' => 'Super Admin',
                'password' => Hash::make(self::DEV_PASSWORD),
                'email_verified_at' => now(),
                'type' => UserType::SuperAdmin,
                // All four tenant/company FKs left NULL — the composite
                // DB CHECK 'users_super_admin_no_tenant_or_company_check'
                // would reject any other shape.
            ],
        );
    }
}
