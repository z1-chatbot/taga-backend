<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'store_id',
        'category_id',
        'name',
        'description',
        'short_description',
        'price',
        'sale_price',
        'sku',
        'stock_quantity',
        'average_rating',
        'rating_count',
        // Medicine identity
        'generic_name',
        'brand_name',
        'manufacturer',
        'active_ingredients',
        // Dosing
        'strength',
        'dosage_form',
        'pack_size',
        'route_of_administration',
        // Regulatory / safety
        'requires_prescription',
        'is_controlled_substance',
        'drug_schedule',
        'nafdac_number',
        'storage_conditions',
        // Current stock batch
        'batch_number',
        'expiry_date',
        // Clinical guidance
        'directions_for_use',
        'side_effects',
        'warnings',
        'contraindications',
        // Common attributes
        'highlighted_features',
        'is_featured',
        'is_active',
        'images',
        'weight_kg',
        'package_dimensions',
        'free_shipping',
        'meta_title',
        'meta_description',
        'payment_method_restriction',
        'has_variations',
        'default_variation_id'
    ];

    protected $casts = [
        'images' => 'array',
        'active_ingredients' => 'array',
        'package_dimensions' => 'array',
        'highlighted_features' => 'array',
        'is_featured' => 'boolean',
        'is_active' => 'boolean',
        'requires_prescription' => 'boolean',
        'is_controlled_substance' => 'boolean',
        'free_shipping' => 'boolean',
        'has_variations' => 'boolean',
        'expiry_date' => 'date',
        'price' => 'decimal:2',
        // Note: sale_price is NOT cast here because getSalePriceAttribute() accessor handles it
        'average_rating' => 'decimal:2',
        'rating_count' => 'integer',
        'weight_kg' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime'
    ];

    // Mutators to handle empty strings
    public function setDescriptionAttribute($value)
    {
        $this->attributes['description'] = $value ?: '';
    }

    public function setShortDescriptionAttribute($value)
    {
        $this->attributes['short_description'] = $value ?: '';
    }

    public function setMetaTitleAttribute($value)
    {
        $this->attributes['meta_title'] = $value ?: '';
    }

    public function setMetaDescriptionAttribute($value)
    {
        $this->attributes['meta_description'] = $value ?: '';
    }

    // Relationships
    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function attributeValues()
    {
        return $this->hasMany(ProductAttributeValue::class);
    }

    /**
     * Back-compat shim.
     *
     * The `product_category` string column was replaced by a real category_id FK, but
     * PricingConfiguration and SaleEvent still match products by category slug. This
     * keeps those read paths working; eager-load `category` to avoid N+1.
     */
    public function getProductCategoryAttribute(): ?string
    {
        return $this->category?->slug;
    }

    /**
     * Flexible category attributes flattened to key => typed value, for API output.
     */
    public function getAttributesMapAttribute(): array
    {
        return $this->attributeValues
            ->filter(fn (ProductAttributeValue $value) => $value->attribute !== null)
            ->mapWithKeys(fn (ProductAttributeValue $value) => [
                $value->attribute->key => $value->typedValue(),
            ])
            ->all();
    }

    /**
     * Whether this listing can legally be sold by the given store.
     * Rx and controlled items need an approved, suitably-permitted vendor.
     */
    public function isSellableBy(?Store $store): bool
    {
        if (! $this->requires_prescription && ! $this->is_controlled_substance) {
            return true; // General sale items need no licence.
        }

        if (! $store) {
            return false;
        }

        // A lapsed licence revokes the permission even though the flags remain set,
        // so expiry is checked alongside the flags rather than instead of them.
        if (! $store->isLicenceValid()) {
            return false;
        }

        if ($this->is_controlled_substance && ! $store->can_sell_controlled) {
            return false;
        }

        if ($this->requires_prescription && ! $store->can_sell_prescription) {
            return false;
        }

        return true;
    }

    public function isExpired(): bool
    {
        return $this->expiry_date !== null && $this->expiry_date->isPast();
    }

    public function images()
    {
        return $this->hasMany(ProductImage::class)->ordered();
    }

    public function primaryImage()
    {
        return $this->hasOne(ProductImage::class)->primary();
    }

    public function reviews()
    {
        return $this->hasMany(Review::class);
    }

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function wishlistItems()
    {
        return $this->hasMany(WishlistItem::class);
    }

    public function variations()
    {
        return $this->hasMany(ProductVariation::class);
    }

    public function activeVariations()
    {
        return $this->hasMany(ProductVariation::class)->active()->ordered();
    }

    public function defaultVariation()
    {
        return $this->belongsTo(ProductVariation::class, 'default_variation_id');
    }

    // Accessors

    /**
     * Get the effective sale price (from active sale event OR database sale_price column)
     */
    public function getEffectiveSalePriceAttribute()
    {
        // First check for active sale events
        try {
            $bestSale = $this->best_active_sale;
            if ($bestSale && method_exists($bestSale, 'applyToProduct')) {
                $salePrice = $bestSale->applyToProduct($this);
                if ($salePrice !== null && $salePrice < $this->attributes['price']) {
                    return $salePrice;
                }
            }
        } catch (\Exception $e) {
            // Ignore sale event errors
        }

        // Fall back to database sale_price column
        $dbSalePrice = $this->attributes['sale_price'] ?? null;
        if ($dbSalePrice !== null && (float)$dbSalePrice > 0 && (float)$dbSalePrice < (float)$this->attributes['price']) {
            return (float)$dbSalePrice;
        }

        return null;
    }

    public function getCurrentPriceAttribute()
    {
        // The price the customer pays: sale price if on sale, otherwise regular price
        $effectiveSale = $this->effective_sale_price;
        $basePrice = $effectiveSale ?? (float)$this->attributes['price'];
        
        try {
            if (class_exists('App\\Models\\PricingConfiguration')) {
                return PricingConfiguration::applyToPrice($basePrice, $this->product_category);
            }
        } catch (\Exception $e) {
            // If pricing configuration fails, return base price
        }
        
        return $basePrice;
    }

    public function getOriginalPriceAttribute()
    {
        // The regular price (before any sale discount), with platform markup
        $regularPrice = (float)$this->attributes['price'];
        
        try {
            if (class_exists('App\\Models\\PricingConfiguration')) {
                return PricingConfiguration::applyToPrice($regularPrice, $this->product_category);
            }
        } catch (\Exception $e) {
            // If pricing configuration fails, return regular price
        }
        
        return $regularPrice;
    }

    public function getBasePriceAttribute()
    {
        // Get price without pricing configuration markup (store owner's price)
        $effectiveSale = $this->effective_sale_price;
        return $effectiveSale ?? (float)$this->attributes['price'];
    }

    public function getPlatformMarkupAttribute()
    {
        // Calculate the platform markup amount
        return $this->current_price - $this->base_price;
    }

    public function getIsOnSaleAttribute()
    {
        return $this->effective_sale_price !== null;
    }

    public function getAverageRatingAttribute()
    {
        return $this->reviews()->avg('rating') ?? 0;
    }

    public function getReviewCountAttribute()
    {
        return $this->reviews()->count();
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Products a shopper may actually buy.
     *
     * `active` only means the shop has switched the listing on. This adds the
     * other half: the shop behind it must be licensed to sell. A pharmacy is
     * free to build its catalogue while its licence is under review — none of it
     * is purchasable until you approve that licence.
     *
     * Products with no store are platform-owned and always sellable.
     */
    public function scopeSellable($query)
    {
        return $query->active()->where(function ($q) {
            $q->whereNull('store_id')
              ->orWhereHas('store', fn ($s) => $s->sellable());
        });
    }

    public function scopeFeatured($query)
    {
        return $query->where('is_featured', true);
    }

    public function scopeInStock($query)
    {
        return $query->where('stock_quantity', '>', 0);
    }

    public function scopeLowStock($query)
    {
        $threshold = SystemSetting::lowStockThreshold();
        return $query->where('stock_quantity', '>', 0)
                     ->where('stock_quantity', '<=', $threshold);
    }

    /**
     * Accepts a Category, an id, or a slug.
     */
    public function scopeByCategory($query, $category)
    {
        if ($category instanceof Category) {
            return $query->where('category_id', $category->id);
        }

        if (is_numeric($category)) {
            return $query->where('category_id', (int) $category);
        }

        return $query->whereHas('category', fn ($q) => $q->where('slug', $category));
    }

    /**
     * Products in a category OR any of its descendants, so browsing a top-level
     * category like "Prescription Medicines" returns everything beneath it.
     */
    public function scopeInCategoryTree($query, Category $category)
    {
        $ids = [$category->id];
        $frontier = [$category->id];

        // Iterative rather than recursive: the tree is shallow and this stays one
        // query per level regardless of depth.
        while ($frontier) {
            $frontier = Category::whereIn('parent_id', $frontier)->pluck('id')->all();
            $ids = array_merge($ids, $frontier);
        }

        return $query->whereIn('category_id', $ids);
    }

    public function scopeRequiresPrescription($query, bool $required = true)
    {
        return $query->where('requires_prescription', $required);
    }

    public function scopeNotExpired($query)
    {
        return $query->where(fn ($q) => $q->whereNull('expiry_date')->orWhere('expiry_date', '>=', now()->toDateString()));
    }

    public function scopeByPriceRange($query, $minPrice, $maxPrice)
    {
        return $query->whereBetween('price', [$minPrice, $maxPrice]);
    }

    /**
     * Check if product is low stock based on system settings
     */
    public function getIsLowStockAttribute(): bool
    {
        $threshold = SystemSetting::lowStockThreshold();
        return $this->stock_quantity > 0 && $this->stock_quantity <= $threshold;
    }

    /**
     * Get formatted price with currency symbol
     */
    public function getFormattedPriceAttribute(): string
    {
        $currency = SystemSetting::getValue(SystemSetting::CATEGORY_GENERAL, 'currency_symbol', '₦');
        return $currency . number_format($this->price, 2);
    }

    /**
     * Get formatted sale price with currency symbol
     */
    public function getFormattedSalePriceAttribute(): ?string
    {
        if (!$this->sale_price) {
            return null;
        }
        $currency = SystemSetting::getValue(SystemSetting::CATEGORY_GENERAL, 'currency_symbol', '₦');
        return $currency . number_format($this->sale_price, 2);
    }

    /**
     * Get formatted current price (sale price if available, otherwise regular price)
     */
    public function getFormattedCurrentPriceAttribute(): string
    {
        $currency = SystemSetting::getValue(SystemSetting::CATEGORY_GENERAL, 'currency_symbol', '₦');
        $price = $this->sale_price ?? $this->price;
        return $currency . number_format($price, 2);
    }

    /**
     * Get active sales events that apply to this product
     */
    public function getActiveSalesEventsAttribute()
    {
        try {
            // Check if SaleEvent class exists
            if (!class_exists('App\Models\SaleEvent')) {
                return collect();
            }

            return \App\Models\SaleEvent::active()
                ->where(function ($query) {
                    $query->where('applicable_to', 'all')
                          ->orWhere(function ($q) {
                              $q->where('applicable_to', 'specific_products')
                                ->whereJsonContains('applicable_ids', $this->id);
                          })
                          ->orWhere(function ($q) {
                              $q->where('applicable_to', 'specific_categories')
                                ->whereJsonContains('applicable_ids', $this->product_category);
                          });
                })
                ->byPriority()
                ->get();
        } catch (\Exception $e) {
            return collect();
        }
    }

    /**
     * Get the best active sale for this product
     */
    public function getBestActiveSaleAttribute()
    {
        try {
            $activeSales = $this->active_sales_events;
            
            if ($activeSales->isEmpty()) {
                return null;
            }

            $bestSale = null;
            $bestDiscount = 0;

            foreach ($activeSales as $sale) {
                if ($sale->discount_type === 'percentage') {
                    $discount = $sale->discount_value;
                } elseif ($sale->discount_type === 'fixed_amount') {
                    $discount = ($sale->discount_value / $this->price) * 100;
                } else {
                    continue;
                }

                if ($discount > $bestDiscount) {
                    $bestDiscount = $discount;
                    $bestSale = $sale;
                }
            }

            return $bestSale;
        } catch (\Exception $e) {
            // If there's any error (like table doesn't exist), return null
            return null;
        }
    }

    /**
     * Get the sale price for this product (for API serialization)
     * Returns the effective sale price with platform markup applied
     */
    public function getSalePriceAttribute()
    {
        $effectiveSale = $this->effective_sale_price;
        if ($effectiveSale === null) {
            return null;
        }

        try {
            if (class_exists('App\\Models\\PricingConfiguration')) {
                return PricingConfiguration::applyToPrice($effectiveSale, $this->product_category);
            }
        } catch (\Exception $e) {
            // If pricing configuration fails, return base sale price
        }

        return $effectiveSale;
    }

    /**
     * Update product rating based on approved reviews
     */
    public function updateRating()
    {
        $approvedReviews = $this->reviews()->approved()->get();
        
        $this->rating_count = $approvedReviews->count();
        $this->average_rating = $this->rating_count > 0 
            ? round($approvedReviews->avg('rating'), 2) 
            : 0;
        
        $this->save();
    }

    /**
     * Get price range for products with variations
     */
    public function getPriceRangeAttribute()
    {
        if (!$this->has_variations) {
            return null;
        }

        $variations = $this->activeVariations;
        
        if ($variations->isEmpty()) {
            return null;
        }

        $prices = $variations->pluck('current_price');
        $minPrice = $prices->min();
        $maxPrice = $prices->max();

        if ($minPrice == $maxPrice) {
            return '₦' . number_format($minPrice, 2);
        }

        return '₦' . number_format($minPrice, 2) . ' - ₦' . number_format($maxPrice, 2);
    }

    /**
     * Get total stock across all variations
     */
    public function getTotalStockAttribute()
    {
        if (!$this->has_variations) {
            return $this->stock_quantity;
        }

        return $this->activeVariations->sum('stock_quantity');
    }

    /**
     * Check if product is in stock (including variations)
     */
    public function getIsInStockAttribute()
    {
        if (!$this->has_variations) {
            return $this->stock_quantity > 0;
        }

        return $this->activeVariations->where('stock_quantity', '>', 0)->isNotEmpty();
    }

}
