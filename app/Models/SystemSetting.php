<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SystemSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'category',
        'key',
        'value',
        'label',
        'description',
        'type',
        'is_active',
        'sort_order'
    ];

    protected $casts = [
        'value' => 'json',
        'is_active' => 'boolean',
        'sort_order' => 'integer'
    ];

    // Setting categories
    const CATEGORY_PRODUCT_ATTRIBUTES = 'product_attributes';
    const CATEGORY_SALE_EVENT_TYPES = 'sale_event_types';
    const CATEGORY_COUPON_TYPES = 'coupon_types';
    const CATEGORY_GENERAL = 'general';

    // Setting types
    const TYPE_ARRAY = 'array';
    const TYPE_STRING = 'string';
    const TYPE_BOOLEAN = 'boolean';
    const TYPE_NUMBER = 'number';

    // Scopes
    public function scopeByCategory($query, $category)
    {
        return $query->where('category', $category);
    }

    public function scopeByKey($query, $key)
    {
        return $query->where('key', $key);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('label');
    }

    // Static methods to get common settings
    public static function getProductAttributes()
    {
        return self::byCategory(self::CATEGORY_PRODUCT_ATTRIBUTES)
                   ->active()
                   ->ordered()
                   ->get()
                   ->map(function ($setting) {
                       // Unlike sale-event and coupon types, whose values are
                       // {value,label} descriptors, a product attribute's value IS the
                       // option list (every dosage form, every route, ...). Collapsing
                       // it to a display string silently discarded every option, so the
                       // raw value is returned here.
                       return [
                           'key' => $setting->key,
                           'label' => $setting->label,
                           'value' => $setting->value,
                       ];
                   })
                   ->values()
                   ->toArray();
    }

    public static function getSaleEventTypes()
    {
        return self::byCategory(self::CATEGORY_SALE_EVENT_TYPES)
                   ->active()
                   ->ordered()
                   ->get()
                   ->map(function ($setting) {
                       // Handle both string and array values
                       $displayValue = is_array($setting->value) 
                           ? ($setting->value['label'] ?? $setting->value['value'] ?? $setting->label)
                           : $setting->value;
                       
                       return [
                           'key' => $setting->key,
                           'value' => $displayValue
                       ];
                   })
                   ->values()
                   ->toArray();
    }

    public static function getCouponTypes()
    {
        return self::byCategory(self::CATEGORY_COUPON_TYPES)
                   ->active()
                   ->ordered()
                   ->get()
                   ->map(function ($setting) {
                       // Handle both string and array values
                       $displayValue = is_array($setting->value) 
                           ? ($setting->value['label'] ?? $setting->value['value'] ?? $setting->label)
                           : $setting->value;
                       
                       return [
                           'key' => $setting->key,
                           'value' => $displayValue
                       ];
                   })
                   ->values()
                   ->toArray();
    }

    /**
     * Get setting value by category and key
     */
    public static function getValue($category, $key, $default = null)
    {
        $setting = self::byCategory($category)
                      ->byKey($key)
                      ->active()
                      ->first();

        return $setting ? $setting->value : $default;
    }

    /**
     * Read a setting as a boolean.
     *
     * Values reach us as real booleans when the cast fires, but as "1", "true"
     * or "0" when they were written as strings — so every caller was repeating
     * the same coercion. An unparseable value falls back to $default rather
     * than silently becoming false, which for a switch like enable_cod would
     * turn a typo into a shut-off payment method.
     */
    public static function getBool($category, $key, bool $default = true): bool
    {
        $value = self::getValue($category, $key, $default);

        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    /**
     * Whether cash on delivery is on offer at all.
     *
     * Consulted in two places that must agree: the basket, which decides what
     * to offer, and checkout, which refuses what it will not accept.
     *
     * Defaults to FALSE, and there is deliberately no row to change it. Cash on
     * delivery is not offered — the storefront sends every order as an online
     * payment — so the honest answer when nothing says otherwise is no. Turning
     * it back on means restoring the setting AND adding the option to checkout;
     * flipping this default alone would only make the API accept something no
     * customer can select.
     */
    public static function codEnabled(): bool
    {
        return self::getBool(self::CATEGORY_GENERAL, 'enable_cod', false);
    }

    /**
     * The platform's cut of a new pharmacy's sales, as a percentage.
     *
     * Direction matters and the name hides it: this is money the platform
     * KEEPS, not money the pharmacy earns. Both payout paths do
     *
     *     $commission = $store->calculateCommission($amount);
     *     $netAmount  = $amount - $commission;
     *
     * and record it as `commission_deducted` on store_payouts — so a higher
     * rate means the pharmacy is paid less.
     *
     * The rate lives on the store (stores.commission_rate) and an admin can
     * change it per pharmacy; this setting only decides where a new one starts.
     * Without it every store was created on the column default of 0.00 and the
     * Settings value governed nothing.
     *
     * Unrelated to courier pay, which has its own rates in delivery_settings
     * (agent_commission_percentage, logistics_company_commission_percentage).
     */
    public static function defaultCommissionRate(): float
    {
        return (float) self::getValue(self::CATEGORY_GENERAL, 'default_commission_rate', 0);
    }

    /**
     * The stock level at or below which a product counts as running low.
     *
     * One number, one place. This used to be answered four different ways —
     * this setting, two hardcoded 5s on the admin dashboard, a hardcoded 10 on
     * the API dashboard, and a separate threshold inside the low-stock email —
     * so changing it on the Settings page moved none of the figures an operator
     * actually looked at.
     *
     * There used to be a second copy of this on an Automation screen, which is
     * gone: two numbers answering the same question is how an operator sets one
     * and watches nothing change.
     */
    public static function lowStockThreshold(): int
    {
        return (int) self::getValue(self::CATEGORY_GENERAL, 'low_stock_threshold', 10);
    }

    /**
     * Get active setting values only (for public use)
     */
    public static function getActiveValues($category, $key, $default = null)
    {
        $setting = self::byCategory($category)
                      ->byKey($key)
                      ->active()
                      ->first();

        return $setting ? $setting->value : $default;
    }

    public static function setValue($category, $key, $value, $label = null, $description = null, $type = self::TYPE_ARRAY)
    {
        return self::updateOrCreate(
            ['category' => $category, 'key' => $key],
            [
                'value' => $value,
                'label' => $label ?: ucwords(str_replace('_', ' ', $key)),
                'description' => $description,
                'type' => $type,
                'is_active' => true
            ]
        );
    }

    /**
     * Helper method to get currency symbol
     */
    public static function getCurrencySymbol(): string
    {
        return self::getValue(self::CATEGORY_GENERAL, 'currency_symbol', '₦');
    }

    /**
     * Format amount with currency symbol
     */
    public static function formatCurrency($amount, $decimals = 2): string
    {
        $symbol = self::getCurrencySymbol();
        return $symbol . number_format($amount, $decimals);
    }

    // Initialize default settings
    public static function initializeDefaults()
    {
        // Product Attributes

        // Sale Event Types
        self::setValue(
            self::CATEGORY_SALE_EVENT_TYPES,
            'flash_sale',
            ['value' => 'flash_sale', 'label' => 'Flash Sale', 'color' => 'red'],
            'Flash Sale',
            'Quick promotional sales'
        );

        self::setValue(
            self::CATEGORY_SALE_EVENT_TYPES,
            'seasonal_sale',
            ['value' => 'seasonal_sale', 'label' => 'Seasonal Sale', 'color' => 'blue'],
            'Seasonal Sale',
            'Seasonal promotional events'
        );

        self::setValue(
            self::CATEGORY_SALE_EVENT_TYPES,
            'clearance',
            ['value' => 'clearance', 'label' => 'Clearance Sale', 'color' => 'yellow'],
            'Clearance Sale',
            'Inventory clearance events'
        );

        self::setValue(
            self::CATEGORY_SALE_EVENT_TYPES,
            'black_friday',
            ['value' => 'black_friday', 'label' => 'Black Friday Sale', 'color' => 'black'],
            'Black Friday Sale',
            'Black Friday promotional events'
        );

        self::setValue(
            self::CATEGORY_SALE_EVENT_TYPES,
            'cyber_monday',
            ['value' => 'cyber_monday', 'label' => 'Cyber Monday Sale', 'color' => 'purple'],
            'Cyber Monday Sale',
            'Cyber Monday promotional events'
        );

        // Coupon Types
        self::setValue(
            self::CATEGORY_COUPON_TYPES,
            'percentage',
            ['value' => 'percentage', 'label' => 'Percentage Discount', 'symbol' => '%'],
            'Percentage Discount',
            'Percentage-based discount coupons'
        );

        self::setValue(
            self::CATEGORY_COUPON_TYPES,
            'fixed_amount',
            ['value' => 'fixed_amount', 'label' => 'Fixed Amount Discount', 'symbol' => '₦'],
            'Fixed Amount Discount',
            'Fixed amount discount coupons'
        );

        self::setValue(
            self::CATEGORY_COUPON_TYPES,
            'free_shipping',
            ['value' => 'free_shipping', 'label' => 'Free Shipping', 'symbol' => '🚚'],
            'Free Shipping',
            'Free shipping coupons'
        );
    }
}
