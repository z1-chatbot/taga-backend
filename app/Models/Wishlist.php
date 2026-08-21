<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Wishlist extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'product_id'
    ];

    protected $casts = [
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
    public function scopeByUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    // Static methods
    public static function isInWishlist($userId, $productId)
    {
        return self::where('user_id', $userId)
                   ->where('product_id', $productId)
                   ->exists();
    }

    public static function toggle($userId, $productId)
    {
        $wishlistItem = self::where('user_id', $userId)
                           ->where('product_id', $productId)
                           ->first();

        if ($wishlistItem) {
            $wishlistItem->delete();
            return false; // Removed from wishlist
        } else {
            self::create([
                'user_id' => $userId,
                'product_id' => $productId
            ]);
            return true; // Added to wishlist
        }
    }
}
