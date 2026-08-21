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
        // ProductionSeeder pulls in roles, settings, the pharmacy category tree,
        // category attributes and sample products.
        $this->call(ProductionSeeder::class);

        $this->command->info('✅ Database seeded successfully!');
    }
}
