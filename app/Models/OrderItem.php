<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'product_id',
        'variation_id',
        'quantity',
        'price',
        'total',
        'product_snapshot',
        'variation_snapshot',
        // Prescription authorising this line, and whether it needed one at purchase time
        'prescription_id',
        'required_prescription'
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'total' => 'decimal:2',
        'product_snapshot' => 'array',
        'variation_snapshot' => 'array',
        'required_prescription' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    // Relationships
    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function prescription()
    {
        return $this->belongsTo(Prescription::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variation()
    {
        return $this->belongsTo(ProductVariation::class, 'variation_id');
    }

    // Accessors
    public function getSubtotalAttribute()
    {
        return $this->quantity * $this->price;
    }

    /**
     * What the pharmacy earned on this line — the customer's price without the
     * platform's markup.
     *
     * Read from the snapshot taken at checkout wherever one exists. Store
     * balances were being recomputed from the product's *current* price, so
     * editing a price or starting a sale retroactively changed the money owed on
     * orders that were already delivered and paid for — and could push a store's
     * withdrawable balance below what it had already been paid.
     */
    public function getStoreRevenueAttribute(): float
    {
        $snapshotBase = $this->product_snapshot['base_price'] ?? null;

        if ($snapshotBase !== null) {
            return round((float) $snapshotBase * $this->quantity, 2);
        }

        // Orders placed before the snapshot carried this. The product's price may
        // have moved since, but the line total is at least what was really
        // charged, which is closer than today's price.
        if ($this->product) {
            return round((float) $this->product->base_price * $this->quantity, 2);
        }

        return round((float) $this->total, 2);
    }

    // Boot method to calculate total automatically
    protected static function boot()
    {
        parent::boot();

        static::saving(function ($orderItem) {
            $orderItem->total = $orderItem->quantity * $orderItem->price;
        });
    }
}
