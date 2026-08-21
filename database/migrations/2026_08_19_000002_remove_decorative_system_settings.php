<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Settings the screen offered and nothing obeyed.
 *
 * Each of these was written to `system_settings`, served by getPublicSettings(),
 * typed in the admin's useSystemSettings hook — and then read by no code path
 * that decides anything. Two of them were worse than idle:
 *
 *   store_verification_required  reads as the master switch for pharmacy
 *                                licensing. It is not: canSell() enforces the
 *                                licence unconditionally, so turning this off
 *                                promised something the platform would refuse
 *                                to do — and it should refuse.
 *   enable_multi_vendor          reads as a switch that could shut the
 *                                marketplace off. Nothing consults it.
 *
 * The other four mirror hardcoded enums. Editing `payment_methods` looks like
 * adding a payment method; the allowed list is a literal array in
 * CartController and never moved.
 *
 * Removing the rows is safe: getPublicSettings() carries a hardcoded fallback
 * for every key, so the API keeps answering with the same values it always did.
 * They are removed from that payload in the same change.
 *
 * Deliberately kept: enable_cod, cod_fee_percentage, enable_dynamic_pricing,
 * show_original_price, default_commission_rate. The first and third were wired
 * up properly alongside this migration rather than deleted; the other two are
 * shown on the Pricing screen and are the operator's call, not mine.
 */
return new class extends Migration
{
    private const REMOVED = [
        'store_verification_required',
        'enable_multi_vendor',
        'store_rating_enabled',
        'order_statuses',
        'delivery_types',
        'payment_methods',
    ];

    public function up(): void
    {
        DB::table('system_settings')
            ->where('category', 'general')
            ->whereIn('key', self::REMOVED)
            ->delete();
    }

    /**
     * Restores the rows with the values the seeder originally gave them, so a
     * rollback returns the screen to exactly the state it was in.
     */
    public function down(): void
    {
        $defaults = [
            'store_verification_required' => ['value' => 'true', 'label' => 'Store Verification Required', 'type' => 'boolean'],
            'enable_multi_vendor' => ['value' => 'true', 'label' => 'Enable Multi Vendor', 'type' => 'boolean'],
            'store_rating_enabled' => ['value' => 'true', 'label' => 'Store Rating Enabled', 'type' => 'boolean'],
            'order_statuses' => ['value' => '["pending","confirmed","processing","shipped","delivered","cancelled","refunded","returned"]', 'label' => 'Order Statuses', 'type' => 'array'],
            'delivery_types' => ['value' => '["home_delivery","store_pickup","pickup_station"]', 'label' => 'Delivery Types', 'type' => 'array'],
            'payment_methods' => ['value' => '["card","bank_transfer","paystack","flutterwave","cash_on_delivery","mobile_money","ussd"]', 'label' => 'Payment Methods', 'type' => 'array'],
        ];

        foreach ($defaults as $key => $row) {
            $exists = DB::table('system_settings')
                ->where('category', 'general')
                ->where('key', $key)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('system_settings')->insert([
                'category' => 'general',
                'key' => $key,
                'value' => $row['value'],
                'label' => $row['label'],
                'type' => $row['type'],
                'is_active' => true,
                'sort_order' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
};
