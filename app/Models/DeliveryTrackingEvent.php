<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DeliveryTrackingEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'status',
        'description',
        'location',
        'metadata',
        'created_by',
        'created_by_type'
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    // Relationships
    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // Scopes
    public function scopeForOrder($query, $orderId)
    {
        return $query->where('order_id', $orderId)->orderBy('created_at', 'asc');
    }

    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    // Status constants
    const STATUS_ORDER_CONFIRMED = 'order_confirmed';
    const STATUS_SHOP_PREPARING = 'shop_preparing';
    const STATUS_READY_FOR_PICKUP = 'ready_for_pickup';
    const STATUS_ASSIGNED_TO_AGENT = 'assigned_to_agent';
    const STATUS_PICKED_UP = 'picked_up';
    const STATUS_IN_TRANSIT = 'in_transit';
    const STATUS_ARRIVED_AT_HUB = 'arrived_at_hub';
    const STATUS_OUT_FOR_DELIVERY = 'out_for_delivery';
    const STATUS_DELIVERED = 'delivered';
    const STATUS_FAILED = 'failed';
    const STATUS_RETURNED = 'returned';

    public static function getStatuses()
    {
        return [
            self::STATUS_ORDER_CONFIRMED,
            self::STATUS_SHOP_PREPARING,
            self::STATUS_READY_FOR_PICKUP,
            self::STATUS_ASSIGNED_TO_AGENT,
            self::STATUS_PICKED_UP,
            self::STATUS_IN_TRANSIT,
            self::STATUS_ARRIVED_AT_HUB,
            self::STATUS_OUT_FOR_DELIVERY,
            self::STATUS_DELIVERED,
            self::STATUS_FAILED,
            self::STATUS_RETURNED
        ];
    }

    public static function getStatusLabel($status)
    {
        $labels = [
            self::STATUS_ORDER_CONFIRMED => 'Order Confirmed',
            self::STATUS_SHOP_PREPARING => 'Shop Preparing Package',
            self::STATUS_READY_FOR_PICKUP => 'Ready for Pickup',
            self::STATUS_ASSIGNED_TO_AGENT => 'Assigned to Delivery Agent',
            self::STATUS_PICKED_UP => 'Package Picked Up',
            self::STATUS_IN_TRANSIT => 'In Transit',
            self::STATUS_ARRIVED_AT_HUB => 'Arrived at Destination Hub',
            self::STATUS_OUT_FOR_DELIVERY => 'Out for Delivery',
            self::STATUS_DELIVERED => 'Delivered Successfully',
            self::STATUS_FAILED => 'Delivery Failed',
            self::STATUS_RETURNED => 'Returned to Shop'
        ];

        return $labels[$status] ?? $status;
    }
}
