<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // ── Default Users ─────────────────────────────────────────────────────
        $this->call(UserSeeder::class);
    }
}
