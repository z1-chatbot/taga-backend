<?php

use App\Models\SystemSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The last of the decorative general settings.
 *
 * `show_original_price` was offered on the Settings page and shown as a badge
 * on the Pricing screen, and read by nothing — not the API, not the storefront,
 * not any of the four frontends. The strikethrough price a shopper sees comes
 * from compareAtPrice(), which is sale pricing and unrelated.
 *
 * What it appeared to promise is also something the platform should not do:
 * "original price" here would be the pharmacy's price before platform markup,
 * and publishing that to shoppers would expose the platform's margin on every
 * listing. Removed rather than wired.
 *
 * `default_commission_rate` was the other unread setting and was kept — it now
 * seeds stores.commission_rate for newly registered pharmacies, so the number
 * on the Settings page governs something real.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('system_settings')
            ->where('category', SystemSetting::CATEGORY_GENERAL)
            ->where('key', 'show_original_price')
            ->delete();
    }

    public function down(): void
    {
        $exists = DB::table('system_settings')
            ->where('category', SystemSetting::CATEGORY_GENERAL)
            ->where('key', 'show_original_price')
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('system_settings')->insert([
            'category' => SystemSetting::CATEGORY_GENERAL,
            'key' => 'show_original_price',
            'value' => json_encode(false),
            'type' => SystemSetting::TYPE_BOOLEAN,
            'label' => 'Show Original Price',
            'description' => 'Display the store price alongside the platform price',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
