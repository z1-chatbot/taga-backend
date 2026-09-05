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
     * Whether this person has actually received this product.
     *
     * Delivered, not merely paid for. Payment clears the moment the card is
     * charged, which on this platform is before a pharmacist has dispensed
     * anything — so the old check let somebody review a medicine they had not
     * yet held, and rate the one thing a review is for. Delivery is tracked on
     * the order rather than the line, so this is the whole order arriving.
     *
     * Payment is still required alongside it. The two are not redundant: a
     * refunded order keeps its delivered status, and a refund is not the
     * footing for a verified-purchase badge.
     */
    public static function hasUserPurchasedProduct($userId, $productId): bool
    {
        return \DB::table('orders')
            ->join('order_items', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.user_id', $userId)
            ->where('order_items.product_id', $productId)
            ->where('orders.payment_status', Order::PAYMENT_PAID)
            ->where('orders.status', Order::STATUS_DELIVERED)
            ->exists();
    }

    /**
     * Boot method to handle events
     */
    protected static function boot()
    {
        parent::boot();

        /*
         * Recalculate on every save, not only on approval.
         *
         * The guard used to be `if ($review->is_approved)`, which meant the one
         * transition that matters most -- approved back to not-approved -- was
         * the one that did nothing. Rejecting a review hid it from the list and
         * left its stars in the average forever, and editing your own review
         * (which sends it back for re-approval) did the same. A review nobody
         * can read went on counting.
         */
        static::saved(function ($review) {
            $review->product?->updateRating();
        });

        // When a review is deleted, recalculate product rating.
        //
        // Model events only. A query-builder delete (Review::where(...)->delete())
        // bypasses this entirely, which is why the admin bulk actions iterate
        // models rather than issuing one mass statement.
        static::deleted(function ($review) {
            $review->product?->updateRating();
        });
    }
}
