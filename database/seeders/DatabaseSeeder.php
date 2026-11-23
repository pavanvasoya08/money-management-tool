<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Core\Seeder;

/**
 * Seeds reference data only (permissions, roles, settings).
 * No business records and no user accounts are created here — the first
 * Super Admin is created interactively with `php bin/console admin:create`.
 */
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(PermissionSeeder::class);
        $this->call(RoleSeeder::class);
        $this->call(SettingsSeeder::class);
    }
}
