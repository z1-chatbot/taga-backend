<?php

use App\Models\SystemSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cash on delivery is not offered, so a COD fee cannot be charged.
 *
 * The storefront sends every order as an online payment, `enable_cod` gates the
 * API, and both checkout paths hardcode `'cod_fee' => 0`. The general setting
 * was read by nothing at all — the only code that reads a key by this name is
 * ShippingCalculator, and that reads the separate `delivery_settings` table,
 * behind an `enable_cod_fee` flag that defaults to false.
 *
 * Paused, not retired: the seeder block is commented out rather than deleted,
 * and down() restores the row. If COD is ever switched on, restoring this is
 * only half the job — the fee also has to be wired into OrderController, which
 * currently zeroes it on both paths.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('system_settings')
            ->where('category', SystemSetting::CATEGORY_GENERAL)
            ->where('key', 'cod_fee_percentage')
            ->delete();
    }

    public function down(): void
    {
        $exists = DB::table('system_settings')
            ->where('category', SystemSetting::CATEGORY_GENERAL)
            ->where('key', 'cod_fee_percentage')
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('system_settings')->insert([
            'category' => SystemSetting::CATEGORY_GENERAL,
            'key' => 'cod_fee_percentage',
            'value' => json_encode(2),
            'type' => SystemSetting::TYPE_NUMBER,
            'label' => 'COD Fee Percentage (%)',
            'description' => 'Additional fee for cash on delivery orders',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
