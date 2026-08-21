<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A key the code reads that had no row, so it could never be changed.
 *
 * `default_warehouse_state` decides the shipping origin when a store has no
 * state of its own. Shipping is priced per route, so this silently chose the
 * origin for every such order — and with no row, the hardcoded 'Lagos' fallback
 * was the only value the platform could ever use.
 *
 * The seeded value is that same fallback, so behaviour today is unchanged; the
 * difference is that it can now be seen and edited.
 *
 * Deliberately NOT added here: `product_categories`. CouponController and
 * SaleEventController still read it, but SystemSettingsSeeder deletes it on
 * every run with the note that categories are real rows in `categories` now,
 * not a flat string list. Re-seeding it would resurrect a retired concept and
 * be wiped again on the next seed. Those two controllers were pointed at the
 * categories table instead.
 */
return new class extends Migration
{
    private const ADDED = [
        [
            'category' => 'general',
            'key' => 'default_warehouse_state',
            'value' => '"Lagos"',
            'label' => 'Default Warehouse State',
            'description' => 'Shipping origin used when a store has no state recorded.',
            'type' => 'string',
        ],
    ];

    public function up(): void
    {
        foreach (self::ADDED as $row) {
            $exists = DB::table('system_settings')
                ->where('category', $row['category'])
                ->where('key', $row['key'])
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('system_settings')->insert($row + [
                'is_active' => true,
                'sort_order' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        foreach (self::ADDED as $row) {
            DB::table('system_settings')
                ->where('category', $row['category'])
                ->where('key', $row['key'])
                ->delete();
        }
    }
};
