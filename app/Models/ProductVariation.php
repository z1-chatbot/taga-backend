<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductVariation extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'sku',
        'name',
        // Pack/strength options — the pharmacy equivalent of the old colour/storage matrix
        'strength',
        'pack_size',
        'dosage_form',
        'batch_number',
        'expiry_date',
        'other_specs',
        'price',
        'sale_price',
        'stock_quantity',
        'weight',
        'images',
        'is_active',
        'sort_order'
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'sale_price' => 'decimal:2',
        'weight' => 'decimal:2',
        'stock_quantity' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
        'images' => 'array',
        'other_specs' => 'array',
        'expiry_date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    protected $appends = ['current_price', 'formatted_price', 'in_stock', 'is_on_sale', 'platform_markup', 'original_price'];

    // Relationships
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class, 'variation_id');
    }

    // Accessors
    public function getOriginalPriceAttribute()
    {
        // Original price WITHOUT platform markup (store owner's price)
        return $this->price;
    }

    public function getCurrentPriceAttribute()
    {
        // Current price WITH platform markup (what customer pays)
        // Uses sale_price if on sale, otherwise regular price
        $storePrice = $this->sale_price && $this->sale_price > 0 ? $this->sale_price : $this->price;
        
        try {
            if (class_exists('App\\Models\\PricingConfiguration')) {
                $category = $this->product ? $this->product->product_category : null;
                return \App\Models\PricingConfiguration::applyToPrice($storePrice, $category);
            }
        } catch (\Exception $e) {
            // If pricing configuration fails, return store price
        }
        
        return $storePrice;
    }

    public function getPlatformMarkupAttribute()
    {
        // Calculate the platform markup amount
        $storePrice = $this->sale_price && $this->sale_price > 0 ? $this->sale_price : $this->price;
        return $this->current_price - $storePrice;
    }

    public function getIsOnSaleAttribute()
    {
        return $this->sale_price && $this->sale_price > 0;
    }

    public function getFormattedPriceAttribute()
    {
        return '₦' . number_format($this->current_price, 2);
    }

    public function getInStockAttribute()
    {
        return $this->stock_quantity > 0;
    }

    public function getDiscountPercentageAttribute()
    {
        if ($this->sale_price && $this->price > $this->sale_price) {
            return round((($this->price - $this->sale_price) / $this->price) * 100);
        }
        return 0;
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeInStock($query)
    {
        return $query->where('stock_quantity', '>', 0);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order', 'asc')->orderBy('id', 'asc');
    }

    // Methods
    public function decrementStock(int $quantity): bool
    {
        if ($this->stock_quantity >= $quantity) {
            $this->decrement('stock_quantity', $quantity);
            return true;
        }
        return false;
    }

    public function incrementStock(int $quantity): void
    {
        $this->increment('stock_quantity', $quantity);
    }

    public function getDisplayName(): string
    {
        $parts = [];
        
        if ($this->color) $parts[] = $this->color;
        if ($this->storage) $parts[] = $this->storage;
        if ($this->ram) $parts[] = $this->ram;
        if ($this->size) $parts[] = $this->size;
        
        return !empty($parts) ? implode(' - ', $parts) : $this->name;
    }
}
