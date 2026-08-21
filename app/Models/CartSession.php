<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CartSession extends Model
{
    protected $fillable = [
        'user_id',
        'session_id',
        'coupon_id',
        'coupon_code',
        'discount_amount',
        'subtotal',
        'total'
    ];

    protected $casts = [
        'discount_amount' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'total' => 'decimal:2'
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    /**
     * Get cart session by user or guest ID
     */
    public static function getSession($userId = null, $sessionId = null)
    {
        return static::when($userId, function ($query) use ($userId) {
            return $query->where('user_id', $userId);
        }, function ($query) use ($sessionId) {
            return $query->where('session_id', $sessionId);
        })->first();
    }

    /**
     * Create or update cart session
     */
    public static function updateSession($userId = null, $sessionId = null, $data = [])
    {
        return static::updateOrCreate(
            $userId ? ['user_id' => $userId] : ['session_id' => $sessionId],
            $data
        );
    }
}
