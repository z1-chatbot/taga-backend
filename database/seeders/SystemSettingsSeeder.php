<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\SystemSetting;

class SystemSettingsSeeder extends Seeder
{
    /**
     * Seeds the settings the platform actually reads.
     *
     * Six general settings were removed in August 2026 — they seeded rows the
     * Settings page displayed and no code path obeyed. Re-adding them here
     * would resurrect them on the next seed, which is why they are gone from
     * this file as well as from the table.
     */
    public function run(): void
    {
        // Clean up option lists left over from the phone/gadget and hair eras.
        // `product_categories` is gone for good: categories are now real rows in the
        // `categories` table (see PharmacyCategorySeeder), not a flat string list.
        $oldKeys = [
            'popular_brands', 'storage_options', 'product_conditions',
            'warranty_periods_old', 'hair_types', 'hair_textures',
            'hair_colors', 'hair_lengths', 'wig_styles', 'lace_types',
            'cap_sizes', 'hair_densities',
            'product_categories', 'brands', 'colors', 'storage_capacities',
            'ram_options', 'operating_systems', 'conditions', 'warranty_periods',
        ];

        foreach ($oldKeys as $key) {
            SystemSetting::where('category', SystemSetting::CATEGORY_PRODUCT_ATTRIBUTES)
                         ->where('key', $key)
                         ->delete();
        }

        // Initialize default system settings
        SystemSetting::initializeDefaults();

        // Pharmacy product attributes.
        // These are the option lists shared across the whole catalogue. Anything that
        // only applies to certain categories belongs in `category_attributes` instead.
        SystemSetting::setValue(
            SystemSetting::CATEGORY_PRODUCT_ATTRIBUTES,
            'dosage_forms',
            [
                'Tablet', 'Capsule', 'Caplet', 'Syrup', 'Suspension', 'Solution',
                'Injection', 'Infusion', 'Cream', 'Ointment', 'Gel', 'Lotion',
                'Drops', 'Inhaler', 'Nebuliser Solution', 'Suppository', 'Pessary',
                'Patch', 'Powder', 'Sachet', 'Spray', 'Lozenge',
            ],
            'Dosage Forms',
            'How a medicine is presented'
        );

        SystemSetting::setValue(
            SystemSetting::CATEGORY_PRODUCT_ATTRIBUTES,
            'routes_of_administration',
            [
                'Oral', 'Topical', 'Intravenous (IV)', 'Intramuscular (IM)',
                'Subcutaneous', 'Inhalation', 'Nasal', 'Ophthalmic (Eye)',
                'Otic (Ear)', 'Rectal', 'Vaginal', 'Sublingual', 'Transdermal',
            ],
            'Routes of Administration',
            'How a medicine is taken or given'
        );

        SystemSetting::setValue(
            SystemSetting::CATEGORY_PRODUCT_ATTRIBUTES,
            'storage_conditions',
            [
                'Store below 25°C',
                'Store below 30°C',
                'Store in a cool dry place',
                'Refrigerate (2°C to 8°C)',
                'Freeze (-20°C)',
                'Ultra-cold (-70°C)',
                'Protect from light',
                'Protect from moisture',
            ],
            'Storage Conditions',
            'Required storage handling for a product'
        );

        SystemSetting::setValue(
            SystemSetting::CATEGORY_PRODUCT_ATTRIBUTES,
            'drug_schedules',
            [
                'Unscheduled', 'Pharmacy Only (P)', 'Prescription Only (POM)',
                'Controlled Schedule II', 'Controlled Schedule III',
                'Controlled Schedule IV', 'Controlled Schedule V',
            ],
            'Drug Schedules',
            'Regulatory classification controlling how a medicine may be sold'
        );

        // --- Pharmacy business policy ---------------------------------------
        // Tunable operating rules. Safety/legal invariants are deliberately NOT
        // here — see App\Support\PharmacyPolicy for what is fixed and why.
        SystemSetting::setValue(
            \App\Support\PharmacyPolicy::CATEGORY,
            'min_shelf_life_days',
            0,
            'Minimum Shelf Life (days)',
            'Refuse to sell stock with fewer than this many days before expiry. '
                . '0 blocks only stock that has already expired.',
            SystemSetting::TYPE_NUMBER
        );

        SystemSetting::setValue(
            \App\Support\PharmacyPolicy::CATEGORY,
            'prescription_validity_days',
            180,
            'Prescription Validity (days)',
            'How long an uploaded prescription stays valid when no expiry date is given.',
            SystemSetting::TYPE_NUMBER
        );

        SystemSetting::setValue(
            \App\Support\PharmacyPolicy::CATEGORY,
            'stock_expiry_warning_days',
            90,
            'Stock Expiry Warning (days)',
            'Look-ahead window for "expiring soon" warnings in reports and dashboards.',
            SystemSetting::TYPE_NUMBER
        );

        SystemSetting::setValue(
            \App\Support\PharmacyPolicy::CATEGORY,
            'grant_prescription_on_approval',
            true,
            'Auto-grant Rx Selling on Approval',
            'Whether approving a pharmacy licence automatically allows it to sell '
                . 'prescription medicines. Controlled substances always stay opt-in.',
            SystemSetting::TYPE_BOOLEAN
        );

        SystemSetting::setValue(
            \App\Support\PharmacyPolicy::CATEGORY,
            'allow_admin_prescription_override',
            true,
            'Allow Admin Prescription Override',
            'Whether a platform admin may overturn a store\'s prescription decision. '
                . 'Overrides are always recorded with the reviewer and a reason.',
            SystemSetting::TYPE_BOOLEAN
        );

        SystemSetting::setValue(
            SystemSetting::CATEGORY_PRODUCT_ATTRIBUTES,
            'pack_sizes',
            [
                '10 tablets', '20 tablets', '28 tablets', '30 tablets', '60 tablets',
                '90 tablets', '100ml', '200ml', '500ml', '1 vial', '5 vials',
                '1 ampoule', '10 ampoules', '1 tube', '1 sachet', '10 sachets',
            ],
            'Pack Sizes',
            'Common pack size options'
        );

        // Add more sale event types
        SystemSetting::setValue(
            SystemSetting::CATEGORY_SALE_EVENT_TYPES,
            'summer_sale',
            ['value' => 'summer_sale', 'label' => 'Summer Sale', 'color' => 'orange'],
            'Summer Sale',
            'Summer seasonal promotions'
        );

        SystemSetting::setValue(
            SystemSetting::CATEGORY_SALE_EVENT_TYPES,
            'back_to_school',
            ['value' => 'back_to_school', 'label' => 'Back to School Sale', 'color' => 'green'],
            'Back to School Sale',
            'Back to school promotional events'
        );

        SystemSetting::setValue(
            SystemSetting::CATEGORY_SALE_EVENT_TYPES,
            'christmas_sale',
            ['value' => 'christmas_sale', 'label' => 'Christmas Sale', 'color' => 'red'],
            'Christmas Sale',
            'Christmas holiday promotions'
        );

        SystemSetting::setValue(
            SystemSetting::CATEGORY_SALE_EVENT_TYPES,
            'easter_sale',
            ['value' => 'easter_sale', 'label' => 'Easter Sale', 'color' => 'pink'],
            'Easter Sale',
            'Easter holiday promotions'
        );

        // Add coupon variations
        SystemSetting::setValue(
            SystemSetting::CATEGORY_COUPON_TYPES,
            'buy_one_get_one',
            ['value' => 'buy_one_get_one', 'label' => 'Buy One Get One', 'symbol' => '2️⃣'],
            'Buy One Get One',
            'BOGO promotional coupons'
        );

        SystemSetting::setValue(
            SystemSetting::CATEGORY_COUPON_TYPES,
            'bundle_discount',
            ['value' => 'bundle_discount', 'label' => 'Bundle Discount', 'symbol' => '📦'],
            'Bundle Discount',
            'Discount for purchasing multiple items'
        );

        // General settings
        SystemSetting::setValue(
            SystemSetting::CATEGORY_GENERAL,
            'currency_symbol',
            '₦',
            'Currency Symbol',
            'Default currency symbol for the store',
            SystemSetting::TYPE_STRING
        );

        SystemSetting::setValue(
            SystemSetting::CATEGORY_GENERAL,
            'default_tax_rate',
            0,
            'Default Tax Rate (%)',
            'Default tax rate percentage for products (0% by default, admin can change)',
            SystemSetting::TYPE_NUMBER
        );

        SystemSetting::setValue(
            SystemSetting::CATEGORY_GENERAL,
            'free_shipping_threshold',
            50000,
            'Free Shipping Threshold (₦)',
            'Minimum order amount for free shipping',
            SystemSetting::TYPE_NUMBER
        );

        SystemSetting::setValue(
            SystemSetting::CATEGORY_GENERAL,
            'low_stock_threshold',
            10,
            'Low Stock Alert Threshold',
            'Stock quantity below which low stock alerts are triggered',
            SystemSetting::TYPE_NUMBER
        );

        SystemSetting::setValue(
            SystemSetting::CATEGORY_GENERAL,
            'default_commission_rate',
            0,
            'Default Platform Commission (%)',
            'The share of a pharmacy sale that the platform keeps. Deducted from '
                . 'what the pharmacy is paid, never added to it. Adjustable per '
                . 'pharmacy after registration; this is only the starting rate.',
            SystemSetting::TYPE_NUMBER
        );

        SystemSetting::setValue(
            SystemSetting::CATEGORY_GENERAL,
            'min_payout_amount',
            10000,
            'Minimum Payout Amount (₦)',
            'Minimum amount required for store payout',
            SystemSetting::TYPE_NUMBER
        );

        // Delivery settings
        // Cash on delivery is not offered: the storefront sends every order as an
        // online payment. Offering an operator a switch for a payment method no
        // customer can choose is a promise the platform does not keep, so the row
        // is gone and SystemSetting::codEnabled() now defaults to FALSE — the API
        // refuses COD rather than merely not advertising it.
        //
        // To bring COD back: restore this block with a value of true, AND add the
        // option to the storefront checkout, which currently hardcodes
        // is_pay_on_delivery to false.
        //
        // SystemSetting::setValue(
        //     SystemSetting::CATEGORY_GENERAL,
        //     'enable_cod',
        //     true,
        //     'Enable Cash on Delivery',
        //     'Allow customers to pay on delivery',
        //     SystemSetting::TYPE_BOOLEAN
        // );

        // Cash on delivery is not offered — the storefront sends every order as
        // an online payment and enable_cod gates the API — so a COD fee cannot
        // be charged by any route. Both checkout paths also hardcode
        // `'cod_fee' => 0`. Commented out rather than deleted: if COD is ever
        // switched on, restore this AND wire the fee into OrderController,
        // because uncommenting alone would still charge nothing.
        //
        // SystemSetting::setValue(
        //     SystemSetting::CATEGORY_GENERAL,
        //     'cod_fee_percentage',
        //     2,
        //     'COD Fee Percentage (%)',
        //     'Additional fee for cash on delivery orders',
        //     SystemSetting::TYPE_NUMBER
        // );

        // Store pickup is supported all the way through fulfilment — the admin and
        // agent portals both handle it — but the storefront hardcodes
        // delivery_type to home_delivery, so no customer can choose it. Hidden
        // rather than switched off: the guard in OrderController still defaults to
        // permitting pickup, so the moment checkout offers it, it works.
        //
        // SystemSetting::setValue(
        //     SystemSetting::CATEGORY_GENERAL,
        //     'enable_store_pickup',
        //     true,
        //     'Enable Store Pickup',
        //     'Allow customers to pick up orders from stores',
        //     SystemSetting::TYPE_BOOLEAN
        // );

        // Pricing configuration settings
        SystemSetting::setValue(
            SystemSetting::CATEGORY_GENERAL,
            'enable_dynamic_pricing',
            true,
            'Enable Dynamic Pricing',
            'Allow platform to add markup to product prices',
            SystemSetting::TYPE_BOOLEAN
        );

        // Store settings
        SystemSetting::setValue(
            SystemSetting::CATEGORY_GENERAL,
            'max_products_per_store',
            1000,
            'Max Products Per Store',
            'Maximum number of products a store can list',
            SystemSetting::TYPE_NUMBER
        );

        $this->command->info('System settings seeded successfully!');
    }
}
