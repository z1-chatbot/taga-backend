<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Support\PharmacyPolicy;
use Tests\TestCase;

/**
 * The Settings page: can a value be saved, and does anything read it back?
 *
 * Two separate questions, and the answers differ per setting. Saving works
 * across the board; being read does not. A setting that saves and is never
 * read is the same shape of problem as a permission checkbox with no guard —
 * the screen accepts a decision the software then ignores.
 */
class SystemSettingsTest extends TestCase
{
    private function adminHeaders(): array
    {
        return $this->tokenFor($this->makeUser(['role' => 'admin']));
    }

    private function setSetting(string $category, string $key, $value, string $type = SystemSetting::TYPE_NUMBER): SystemSetting
    {
        SystemSetting::setValue($category, $key, $value, $key, null, $type);

        return SystemSetting::where('category', $category)->where('key', $key)->firstOrFail();
    }

    // ---- saving works --------------------------------------------------------

    public function test_a_setting_can_be_updated_and_is_read_back(): void
    {
        $setting = $this->setSetting(SystemSetting::CATEGORY_GENERAL, 'low_stock_threshold', 10);

        // The endpoint requires label alongside value — the screen sends both.
        $this->putJson("/api/v1/admin/settings/{$setting->id}", [
            'value' => 25,
            'label' => 'Low Stock Threshold',
        ], $this->adminHeaders())->assertOk();

        $this->assertSame(25, SystemSetting::getValue(SystemSetting::CATEGORY_GENERAL, 'low_stock_threshold'));
    }

    public function test_the_settings_screen_is_closed_to_non_admins(): void
    {
        $setting = $this->setSetting(SystemSetting::CATEGORY_GENERAL, 'low_stock_threshold', 10);
        $staff = $this->makeUser(['role' => 'staff']);

        $this->getJson('/api/v1/admin/settings', $this->tokenFor($staff))->assertStatus(403);

        $this->putJson("/api/v1/admin/settings/{$setting->id}", [
            'value' => 99,
        ], $this->tokenFor($staff))->assertStatus(403);

        $this->assertSame(10, SystemSetting::getValue(SystemSetting::CATEGORY_GENERAL, 'low_stock_threshold'));
    }

    // ---- settings that genuinely drive behaviour -----------------------------

    public function test_low_stock_threshold_changes_what_counts_as_low_stock(): void
    {
        $this->setSetting(SystemSetting::CATEGORY_GENERAL, 'low_stock_threshold', 5);

        $product = \App\Models\Product::factory()->create(['stock_quantity' => 8]);

        $this->assertFalse($product->fresh()->is_low_stock, '8 is above a threshold of 5');

        $this->setSetting(SystemSetting::CATEGORY_GENERAL, 'low_stock_threshold', 10);

        $this->assertTrue($product->fresh()->is_low_stock, '8 is below a threshold of 10');
    }

    public function test_the_pharmacy_policy_settings_are_read(): void
    {
        // These five drive real refusals — the shelf-life floor is what stops a
        // nearly-expired medicine being sold.
        $this->setSetting('pharmacy', 'min_shelf_life_days', 90);
        $this->assertSame(90, PharmacyPolicy::minShelfLifeDays());

        $this->setSetting('pharmacy', 'prescription_validity_days', 30);
        $this->assertSame(30, PharmacyPolicy::prescriptionValidityDays());

        // Booleans are stored as JSON and can come back as bool, int or string.
        $this->setSetting('pharmacy', 'grant_prescription_on_approval', false, SystemSetting::TYPE_BOOLEAN);
        $this->assertFalse(PharmacyPolicy::grantPrescriptionOnApproval());

        $this->setSetting('pharmacy', 'allow_admin_prescription_override', false, SystemSetting::TYPE_BOOLEAN);
        $this->assertFalse(PharmacyPolicy::allowAdminPrescriptionOverride());
    }

    // ---- switches that were decoration and are now real ----------------------

    /**
     * The Pricing screen told operators that "configurations below will not be
     * applied until dynamic pricing is enabled in System Settings". That was
     * false — applyToPrice() ignored the switch and every markup applied
     * regardless. Now the sentence is true.
     */
    public function test_dynamic_pricing_off_stops_markups_being_applied(): void
    {
        \App\Models\PricingConfiguration::create([
            'name' => 'Platform markup',
            'category' => null,
            'type' => 'percentage',
            'value' => 20,
            'is_active' => true,
            'priority' => 1,
        ]);

        $this->setSetting(SystemSetting::CATEGORY_GENERAL, 'enable_dynamic_pricing', true, SystemSetting::TYPE_BOOLEAN);
        $this->assertEqualsWithDelta(
            1200.0,
            (float) \App\Models\PricingConfiguration::applyToPrice(1000),
            0.01,
            'with the switch on, the 20% markup applies'
        );

        $this->setSetting(SystemSetting::CATEGORY_GENERAL, 'enable_dynamic_pricing', false, SystemSetting::TYPE_BOOLEAN);
        $this->assertEqualsWithDelta(
            1000.0,
            (float) \App\Models\PricingConfiguration::applyToPrice(1000),
            0.01,
            'with the switch off, the base price is untouched'
        );
    }

    public function test_dynamic_pricing_defaults_to_on_when_unset(): void
    {
        // An operator who never touches the switch keeps the old behaviour.
        SystemSetting::where('category', SystemSetting::CATEGORY_GENERAL)
            ->where('key', 'enable_dynamic_pricing')
            ->delete();

        $this->assertTrue(\App\Models\PricingConfiguration::dynamicPricingEnabled());
    }

    // ---- settings removed because nothing obeyed them ------------------------

    /**
     * Six general settings were deleted. Two of them were worse than idle:
     * store_verification_required read as the master switch for pharmacy
     * licensing (which canSell() enforces unconditionally), and
     * enable_multi_vendor read as a switch that could close the marketplace.
     */
    public function test_the_decorative_settings_are_gone(): void
    {
        $removed = [
            'store_verification_required',
            'enable_multi_vendor',
            'store_rating_enabled',
            'order_statuses',
            'delivery_types',
            'payment_methods',
        ];

        foreach ($removed as $key) {
            $this->assertNull(
                SystemSetting::where('category', SystemSetting::CATEGORY_GENERAL)->where('key', $key)->first(),
                "{$key} should have been removed — it drove nothing"
            );
        }
    }

    public function test_licensing_cannot_be_switched_off_by_any_setting(): void
    {
        // The removed switch implied this was optional. It is not, and putting
        // the row back by hand must not change that.
        $this->setSetting(SystemSetting::CATEGORY_GENERAL, 'store_verification_required', false, SystemSetting::TYPE_BOOLEAN);

        $store = \App\Models\Store::create([
            'owner_id' => $this->makeUser(['role' => 'store_owner'])->id,
            'name' => 'Unverified Pharmacy',
            'slug' => 'unverified-'.uniqid(),
            'email' => 'shop'.uniqid().'@stores.test',
            'phone' => '08012345678',
            'address' => '1 Test Road',
            'city' => 'Ikeja',
            'state' => 'Lagos',
            'status' => 'active',
            'verification_status' => \App\Models\Store::VERIFICATION_PENDING,
        ]);

        $this->assertFalse($store->canSell(), 'an unapproved licence still blocks selling');
        $this->assertSame(0, \App\Models\Store::sellable()->where('id', $store->id)->count());
    }

    /**
     * Cash on delivery is gone, not paused.
     *
     * This used to check that the fee setting had no row while reminding
     * whoever switched COD back on that both checkout paths hardcoded the fee
     * to zero. There is nothing left to switch back on: the columns, the
     * settings, the basket branch and the checkout parameter have all been
     * removed, so the reminder has been replaced by a check that none of it
     * came back.
     */
    public function test_cash_on_delivery_is_gone_from_settings_and_checkout(): void
    {
        $this->assertNull(
            SystemSetting::where('category', SystemSetting::CATEGORY_GENERAL)
                ->whereIn('key', ['cod_fee_percentage', 'enable_cod'])
                ->first(),
            'cash on delivery is not offered, so neither setting should have a row'
        );

        foreach (['OrderController', 'CartController'] as $controller) {
            $source = file_get_contents(app_path("Http/Controllers/Api/{$controller}.php"));

            $this->assertStringNotContainsString("cash_on_delivery", $source, $controller);
            $this->assertStringNotContainsString("is_pay_on_delivery", $source, $controller);
            $this->assertStringNotContainsString("cod_fee", $source, $controller);
        }
    }

    // ---- keys the code reads that have no row --------------------------------

    /**
     * Two keys are read with a hardcoded fallback but have no row, so they can
     * never be changed from the Settings page — the fallback is the only value
     * the platform will ever use.
     */
    public function test_the_warehouse_state_is_now_settable(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/Api/OrderController.php'));

        $this->assertStringContainsString("'default_warehouse_state'", $source);
        $this->assertNull(
            SystemSetting::where('key', 'default_warehouse_state')->first(),
            'no row exists, so the Settings page cannot offer it'
        );

        // product_categories was NOT given a row. The seeder deletes that key on
        // every run — categories are real rows in `categories` now — so seeding
        // it would resurrect a retired concept and be wiped again. The three
        // controllers that read it were pointed at the categories table.
        foreach ([
            'Http/Controllers/Admin/CouponController.php',
            'Http/Controllers/Admin/SaleEventController.php',
            'Http/Controllers/Store/CouponController.php',
        ] as $file) {
            $this->assertStringNotContainsString(
                "getValue('product_attributes', 'product_categories'",
                file_get_contents(app_path($file)),
                "{$file} must not read the retired product_categories setting"
            );
        }
    }

    /**
     * default_commission_rate was the other setting nothing read. Rather than
     * delete it, it now seeds stores.commission_rate — the column
     * Store::calculateCommission() actually charges against.
     */
    public function test_the_default_commission_rate_seeds_a_new_pharmacy(): void
    {
        $this->setSetting(SystemSetting::CATEGORY_GENERAL, 'default_commission_rate', 12.5);

        $this->post('/api/v1/sell/register', [
            'owner_name' => 'Ada Pharmacist',
            'owner_email' => 'ada'.uniqid().'@pharmacy.test',
            'owner_password' => 'password123',
            'owner_password_confirmation' => 'password123',
            'owner_phone' => '08012345678',
            'name' => 'Ada Pharmacy',
            'email' => 'shop'.uniqid().'@pharmacy.test',
            'phone' => '08012345678',
            'address' => '1 Test Road',
            'city' => 'Ikeja',
            'state' => 'Lagos',
            'license_document' => \Illuminate\Http\UploadedFile::fake()->create('licence.pdf', 40, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertSuccessful();

        $store = \App\Models\Store::where('name', 'Ada Pharmacy')->firstOrFail();

        $this->assertEqualsWithDelta(12.5, (float) $store->commission_rate, 0.01);
        $this->assertEqualsWithDelta(
            125.0,
            (float) $store->calculateCommission(1000),
            0.01,
            'the seeded rate is the rate actually charged'
        );
    }

    public function test_show_original_price_is_gone(): void
    {
        // Read by nothing, and what it implied — publishing the pharmacy's
        // pre-markup price to shoppers — would expose the platform margin.
        $this->assertNull(
            SystemSetting::where('category', SystemSetting::CATEGORY_GENERAL)
                ->where('key', 'show_original_price')
                ->first()
        );
    }
}
