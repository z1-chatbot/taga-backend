<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Carbon\Carbon;

class Coupon extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'store_id',
        'code',
        'name',
        'description',
        'type', // percentage, fixed_amount, free_shipping
        'value',
        'minimum_amount',
        'maximum_discount',
        'usage_limit',
        'used_count',
        'user_limit', // per user limit
        'valid_from',
        'valid_until',
        'is_active',
        'applicable_to', // all, specific_products, specific_categories
        'applicable_ids', // JSON array of product/category IDs
        'exclude_sale_items',
        'first_order_only',
        'auto_apply' // for automatic sales like Black Friday
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'minimum_amount' => 'decimal:2',
        'maximum_discount' => 'decimal:2',
        'usage_limit' => 'integer',
        'used_count' => 'integer',
        'user_limit' => 'integer',
        'valid_from' => 'datetime',
        'valid_until' => 'datetime',
        'is_active' => 'boolean',
        'applicable_ids' => 'array',
        'exclude_sale_items' => 'boolean',
        'first_order_only' => 'boolean',
        'auto_apply' => 'boolean'
    ];

    // Coupon types
    const TYPE_PERCENTAGE = 'percentage';
    const TYPE_FIXED_AMOUNT = 'fixed_amount';
    const TYPE_FREE_SHIPPING = 'free_shipping';

    // Applicable to
    const APPLICABLE_ALL = 'all';
    const APPLICABLE_PRODUCTS = 'specific_products';
    const APPLICABLE_CATEGORIES = 'specific_categories';

    // Relationships
    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function usages()
    {
        return $this->hasMany(CouponUsage::class);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true)
                    ->where('valid_from', '<=', now())
                    ->where('valid_until', '>=', now());
    }

    public function scopeAutoApply($query)
    {
        return $query->where('auto_apply', true);
    }

    /**
     * Whether a code is worth printing in an email.
     *
     * Deliberately narrower than isValid(), which answers "can this basket use
     * it" and needs a cart. This answers the only question an email can ask
     * before there is a basket: does the code exist, is it live today, and is
     * there any of it left.
     *
     * It exists because the welcome email used to promise a hardcoded
     * 'WELCOME10' that was never created, so the first thing a new customer
     * did with us was be told their discount code was invalid.
     */
    public static function usable(?string $code): bool
    {
        if (! $code) {
            return false;
        }

        return static::query()
            ->byCode($code)
            ->active()
            ->where(function ($query) {
                $query->whereNull('usage_limit')
                    ->orWhereColumn('used_count', '<', 'usage_limit');
            })
            ->exists();
    }

    public function scopeByCode($query, $code)
    {
        return $query->where('code', strtoupper($code));
    }

    public function scopePlatformWide($query)
    {
        return $query->whereNull('store_id');
    }

    public function scopeByStore($query, $storeId)
    {
        return $query->where('store_id', $storeId);
    }

    // Methods
    public function isValid($userId = null, $cartTotal = 0, $cartItems = [])
    {
        // Check if coupon is active
        if (!$this->is_active) {
            return ['valid' => false, 'message' => 'Coupon is not active'];
        }

        // Check date validity
        if (now()->lt($this->valid_from) || now()->gt($this->valid_until)) {
            return ['valid' => false, 'message' => 'Coupon has expired or not yet valid'];
        }

        // Check usage limit
        if ($this->usage_limit && $this->used_count >= $this->usage_limit) {
            return ['valid' => false, 'message' => 'Coupon usage limit exceeded'];
        }

        // Check user limit
        if ($userId && $this->user_limit) {
            $userUsageCount = $this->usages()->where('user_id', $userId)->count();
            if ($userUsageCount >= $this->user_limit) {
                return ['valid' => false, 'message' => 'You have reached the usage limit for this coupon'];
            }
        }

        // Check minimum amount
        if ($this->minimum_amount && $cartTotal < $this->minimum_amount) {
            return ['valid' => false, 'message' => "Minimum order amount of $" . $this->minimum_amount . " required"];
        }

        // Check first order only
        if ($this->first_order_only && $userId) {
            $hasOrders = Order::where('user_id', $userId)->where('status', '!=', 'cancelled')->exists();
            if ($hasOrders) {
                return ['valid' => false, 'message' => 'This coupon is only valid for first orders'];
            }
        }

        // Check applicable products/categories
        if ($this->applicable_to !== self::APPLICABLE_ALL && !empty($cartItems)) {
            $applicableItems = $this->getApplicableItems($cartItems);
            if (empty($applicableItems)) {
                return ['valid' => false, 'message' => 'This coupon is not applicable to items in your cart'];
            }
        }

        return ['valid' => true, 'message' => 'Coupon is valid'];
    }

    public function calculateDiscount($cartTotal, $cartItems = [], $shippingAmount = 0)
    {
        $validation = $this->isValid(null, $cartTotal, $cartItems);
        if (!$validation['valid']) {
            return 0;
        }

        switch ($this->type) {
            case self::TYPE_PERCENTAGE:
                $discount = ($cartTotal * $this->value) / 100;
                if ($this->maximum_discount && $discount > $this->maximum_discount) {
                    $discount = $this->maximum_discount;
                }
                return $discount;

            case self::TYPE_FIXED_AMOUNT:
                return min($this->value, $cartTotal);

            case self::TYPE_FREE_SHIPPING:
                return $shippingAmount;

            default:
                return 0;
        }
    }

    public function getApplicableItems($cartItems)
    {
        if ($this->applicable_to === self::APPLICABLE_ALL) {
            return $cartItems;
        }

        $applicableItems = [];
        foreach ($cartItems as $item) {
            if ($this->applicable_to === self::APPLICABLE_PRODUCTS) {
                if (in_array($item['product_id'], $this->applicable_ids ?? [])) {
                    $applicableItems[] = $item;
                }
            } elseif ($this->applicable_to === self::APPLICABLE_CATEGORIES) {
                $product = Product::find($item['product_id']);
                if ($product && in_array($product->product_category, $this->applicable_ids ?? [])) {
                    $applicableItems[] = $item;
                }
            }
        }

        return $applicableItems;
    }

    public function incrementUsage($userId = null)
    {
        $this->increment('used_count');
        
        if ($userId) {
            CouponUsage::create([
                'coupon_id' => $this->id,
                'user_id' => $userId,
                'used_at' => now()
            ]);
        }
    }

    // Static methods for special sales
    public static function createBlackFridaySale()
    {
        return self::create([
            'code' => 'BLACKFRIDAY2024',
            'name' => 'Black Friday Sale',
            'description' => 'Massive Black Friday discounts on all hair products',
            'type' => self::TYPE_PERCENTAGE,
            'value' => 40,
            'minimum_amount' => 50,
            'maximum_discount' => 200,
            'valid_from' => Carbon::create(2024, 11, 29, 0, 0, 0),
            'valid_until' => Carbon::create(2024, 12, 2, 23, 59, 59),
            'is_active' => true,
            'applicable_to' => self::APPLICABLE_ALL,
            'auto_apply' => true
        ]);
    }

    public static function createEstateSale()
    {
        return self::create([
            'code' => 'ESTATE50',
            'name' => 'Estate Sale',
            'description' => 'Limited time estate sale - 50% off premium wigs',
            'type' => self::TYPE_PERCENTAGE,
            'value' => 50,
            'minimum_amount' => 200,
            'usage_limit' => 100,
            'valid_from' => now(),
            'valid_until' => now()->addDays(7),
            'is_active' => true,
            'applicable_to' => self::APPLICABLE_CATEGORIES,
            'applicable_ids' => [1, 2], // Premium wig categories
            'auto_apply' => false
        ]);
    }
}
