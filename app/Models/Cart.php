<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Cart extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'session_id',
        'product_id',
        'variation_id',
        'quantity',
        'price',
        // Lets a shopper attach a prescription before checkout completes.
        'prescription_id'
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'quantity' => 'integer',
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

    public function variation()
    {
        return $this->belongsTo(ProductVariation::class, 'variation_id');
    }

    // Accessors
    public function getSubtotalAttribute()
    {
        return $this->quantity * $this->price;
    }

    public function getTotalAttribute()
    {
        return $this->quantity * $this->price;
    }

    // Scopes
    public function scopeByUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeBySession($query, $sessionId)
    {
        return $query->where('session_id', $sessionId);
    }

    // Static methods
    public static function getCartItems($userId = null, $sessionId = null)
    {
        $query = self::with('product');
        
        if ($userId) {
            $query->where('user_id', $userId);
        } elseif ($sessionId) {
            $query->where('session_id', $sessionId);
        }
        
        return $query->get();
    }

    public static function getCartTotal($userId = null, $sessionId = null)
    {
        $items = self::getCartItems($userId, $sessionId);
        return $items->sum('subtotal');
    }

    public static function getCartCount($userId = null, $sessionId = null)
    {
        $items = self::getCartItems($userId, $sessionId);
        return $items->sum('quantity');
    }
}
