<?php

use App\Models\SystemSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Two switches for things a customer cannot choose.
 *
 * Both were real guards on the API, and both stay as guards — what goes is the
 * Settings row, because offering an operator a switch for a payment or delivery
 * method the storefront never presents is a control that cannot be observed to
 * work. They differ in what "no row" should mean:
 *
 *  - enable_cod: cash on delivery is a settled no. Removing the row leaves
 *    codEnabled() on its new default of FALSE, so the API refuses COD outright
 *    rather than merely not advertising it.
 *
 *  - enable_store_pickup: pickup is supported right through fulfilment (the
 *    admin and agent portals both handle it); only the storefront doesn't offer
 *    it yet. Its guard keeps defaulting to TRUE, so the day checkout presents
 *    the option it simply works.
 *
 * down() restores both rows with the values they held.
 */
return new class extends Migration
{
    private const KEYS = ['enable_cod', 'enable_store_pickup'];

    public function up(): void
    {
        DB::table('system_settings')
            ->where('category', SystemSetting::CATEGORY_GENERAL)
            ->whereIn('key', self::KEYS)
            ->delete();
    }

    public function down(): void
    {
        $rows = [
            'enable_cod' => ['Enable Cash on Delivery', 'Allow customers to pay on delivery'],
            'enable_store_pickup' => ['Enable Store Pickup', 'Allow customers to pick up orders from stores'],
        ];

        foreach ($rows as $key => [$label, $description]) {
            $exists = DB::table('system_settings')
                ->where('category', SystemSetting::CATEGORY_GENERAL)
                ->where('key', $key)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('system_settings')->insert([
                'category' => SystemSetting::CATEGORY_GENERAL,
                'key' => $key,
                'value' => json_encode(true),
                'type' => SystemSetting::TYPE_BOOLEAN,
                'label' => $label,
                'description' => $description,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
};
