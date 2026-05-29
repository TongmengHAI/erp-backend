<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeders\Framework\DefaultPermissionsSeeder;
use Database\Seeders\Framework\DefaultRolesSeeder;
use Database\Seeders\Framework\SuperAdminSeeder;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // Framework seeders run first — they bootstrap the permission catalog
        // and the default role definitions that the rest of the app depends on.
        $this->call([
            DefaultPermissionsSeeder::class,
            DefaultRolesSeeder::class,
            // SuperAdminSeeder is dev-only (gated to local/testing via
            // RuntimeException on the wrong environment). Wires the
            // canonical local SA user (superadmin@myerp.local / superadmin).
            // Production SA creation uses `php artisan super-admin:create`.
            SuperAdminSeeder::class,
        ]);

        // (Country templates / demo tenant seeders run from explicit artisan
        // invocations in dev/CI — not from the default db:seed path.)
    }
}
