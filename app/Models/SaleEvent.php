<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SaleEvent extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'store_id',
        'name',
        'description',
        'type', // flash_sale, seasonal_sale, clearance, black_friday, cyber_monday
        'discount_type', // percentage, fixed_amount
        'discount_value',
        'start_date',
        'end_date',
        'is_active',
        'banner_image',
        'banner_text',
        'applicable_to', // all, specific_products, specific_categories
        'applicable_ids',
        'minimum_purchase',
        'maximum_discount',
        'auto_activate',
        'priority' // higher priority sales override lower ones
    ];

    protected $casts = [
        'discount_value' => 'decimal:2',
        'start_date' => 'datetime',
        'end_date' => 'datetime',
        'is_active' => 'boolean',
        'applicable_ids' => 'array',
        'minimum_purchase' => 'decimal:2',
        'maximum_discount' => 'decimal:2',
        'auto_activate' => 'boolean',
        'priority' => 'integer'
    ];

    // Sale types
    const TYPE_FLASH_SALE = 'flash_sale';
    const TYPE_SEASONAL = 'seasonal_sale';
    const TYPE_CLEARANCE = 'clearance';
    const TYPE_BLACK_FRIDAY = 'black_friday';
    const TYPE_CYBER_MONDAY = 'cyber_monday';
    const TYPE_CHRISTMAS = 'christmas_sale';
    const TYPE_NEW_YEAR = 'new_year';
    const TYPE_VALENTINES = 'valentines';
    const TYPE_MOTHERS_DAY = 'mothers_day';

    // Discount types
    const DISCOUNT_PERCENTAGE = 'percentage';
    const DISCOUNT_FIXED = 'fixed_amount';

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true)
                    ->where('start_date', '<=', now())
                    ->where('end_date', '>=', now());
    }

    public function scopeUpcoming($query)
    {
        return $query->where('start_date', '>', now());
    }

    public function scopeByType($query, $type)
    {
        return $query->where('type', $type);
    }

    public function scopeByPriority($query)
    {
        return $query->orderBy('priority', 'desc');
    }

    public function scopePlatformWide($query)
    {
        return $query->whereNull('store_id');
    }

    public function scopeByStore($query, $storeId)
    {
        return $query->where('store_id', $storeId);
    }

    // Relationships
    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    // Methods
    public function isCurrentlyActive()
    {
        return $this->is_active && 
               now()->between($this->start_date, $this->end_date);
    }

    public function getTimeRemaining()
    {
        if (!$this->isCurrentlyActive()) {
            return null;
        }

        return now()->diffInSeconds($this->end_date);
    }

    public function applyToProduct($product)
    {
        if (!$this->isCurrentlyActive()) {
            return $product->price;
        }

        if ($this->applicable_to === 'all' || 
            ($this->applicable_to === 'specific_products' && in_array($product->id, $this->applicable_ids ?? [])) ||
            ($this->applicable_to === 'specific_categories' && in_array($product->product_category, $this->applicable_ids ?? []))) {
            
            $originalPrice = $product->price;
            
            if ($this->discount_type === self::DISCOUNT_PERCENTAGE) {
                $discount = ($originalPrice * $this->discount_value) / 100;
                if ($this->maximum_discount && $discount > $this->maximum_discount) {
                    $discount = $this->maximum_discount;
                }
                return $originalPrice - $discount;
            } else {
                return max(0, $originalPrice - $this->discount_value);
            }
        }

        return $product->price;
    }

    /**
     * Check if sale applies to a specific product
     */
    public function appliesToProduct($product)
    {
        if (!$this->isCurrentlyActive()) {
            return false;
        }

        return $this->applicable_to === 'all' || 
               ($this->applicable_to === 'specific_products' && in_array($product->id, $this->applicable_ids ?? [])) ||
               ($this->applicable_to === 'specific_categories' && in_array($product->product_category, $this->applicable_ids ?? []));
    }

    /**
     * Calculate discount amount for a given price
     */
    public function calculateDiscount($price)
    {
        if (!$this->isCurrentlyActive()) {
            return 0;
        }

        if ($this->discount_type === self::DISCOUNT_PERCENTAGE) {
            $discount = ($price * $this->discount_value) / 100;
            if ($this->maximum_discount && $discount > $this->maximum_discount) {
                $discount = $this->maximum_discount;
            }
            return $discount;
        } else {
            return min($price, $this->discount_value);
        }
    }

    // Static methods for creating common sales
    public static function createBlackFridayEvent()
    {
        return self::create([
            'name' => 'Black Friday Mega Sale',
            'description' => 'Biggest sale of the year! Up to 70% off all hair products',
            'type' => self::TYPE_BLACK_FRIDAY,
            'discount_type' => self::DISCOUNT_PERCENTAGE,
            'discount_value' => 50,
            'start_date' => now()->startOfDay(),
            'end_date' => now()->addDays(4)->endOfDay(),
            'is_active' => true,
            'banner_image' => 'black-friday-banner.jpg',
            'banner_text' => 'BLACK FRIDAY: 50% OFF EVERYTHING!',
            'applicable_to' => 'all',
            'minimum_purchase' => 0,
            'maximum_discount' => 500,
            'auto_activate' => true,
            'priority' => 100
        ]);
    }

    public static function createFlashSale($productIds, $discountPercent, $hours = 24)
    {
        return self::create([
            'name' => 'Flash Sale - ' . $discountPercent . '% Off',
            'description' => 'Limited time flash sale on selected items',
            'type' => self::TYPE_FLASH_SALE,
            'discount_type' => self::DISCOUNT_PERCENTAGE,
            'discount_value' => $discountPercent,
            'start_date' => now(),
            'end_date' => now()->addHours($hours),
            'is_active' => true,
            'banner_text' => "FLASH SALE: {$discountPercent}% OFF - {$hours} HOURS ONLY!",
            'applicable_to' => 'specific_products',
            'applicable_ids' => $productIds,
            'auto_activate' => true,
            'priority' => 90
        ]);
    }
}
