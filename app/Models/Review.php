<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Review extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'product_id',
        'rating',
        'title',
        'comment',
        'is_verified_purchase',
        'is_approved',
        'helpful_count'
    ];

    protected $casts = [
        'rating' => 'integer',
        'is_verified_purchase' => 'boolean',
        'is_approved' => 'boolean',
        'helpful_count' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    // Relationships
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    // Scopes
    public function scopeApproved($query)
    {
        return $query->where('is_approved', true);
    }

    public function scopeVerifiedPurchase($query)
    {
        return $query->where('is_verified_purchase', true);
    }

    public function scopeByRating($query, $rating)
    {
        return $query->where('rating', $rating);
    }

    public function scopeRecent($query)
    {
        return $query->orderBy('created_at', 'desc');
    }

    // Accessors
    public function getRatingStarsAttribute()
    {
        return str_repeat('★', $this->rating) . str_repeat('☆', 5 - $this->rating);
    }

    public function getTimeAgoAttribute()
    {
        return $this->created_at->diffForHumans();
    }

    /**
     * Check if user has purchased this product
     */
    public static function hasUserPurchasedProduct($userId, $productId): bool
    {
        return \DB::table('orders')
            ->join('order_items', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.user_id', $userId)
            ->where('order_items.product_id', $productId)
            ->where('orders.payment_status', Order::PAYMENT_PAID)
            ->exists();
    }

    /**
     * Boot method to handle events
     */
    protected static function boot()
    {
        parent::boot();

        // When a review is created or updated, recalculate product rating
        static::saved(function ($review) {
            if ($review->is_approved) {
                $review->product->updateRating();
            }
        });

        // When a review is deleted, recalculate product rating
        static::deleted(function ($review) {
            $review->product->updateRating();
        });
    }
}
