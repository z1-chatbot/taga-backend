<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\EmailAutomationSetting;

class EmailAutomationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        EmailAutomationSetting::initializeDefaults();
    }
}
