<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Account content is provisioned from the reviewed five-sheet pack on
        // registration. Restoring a private historical snapshot is an explicit
        // operator action, never the default seed for a clean installation.
    }
}
