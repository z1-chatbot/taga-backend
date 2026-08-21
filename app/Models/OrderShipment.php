<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class OrderShipment extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'store_id',
        'tracking_number',
        'logistics_company_id',
        'delivery_agent_id',
        'pickup_agent_id',
        'status',
        'shipping_fee',
        'weight',
        'dimensions',
        'items',
        'estimated_delivery_days',
        'ready_at',
        'assigned_at',
        'picked_up_at',
        'in_transit_at',
        'arrived_at_hub_at',
        'out_for_delivery_at',
        'delivered_at'
    ];

    protected $casts = [
        'shipping_fee' => 'decimal:2',
        'weight' => 'decimal:2',
        'dimensions' => 'array',
        'items' => 'array',
        'estimated_delivery_days' => 'integer',
        'ready_at' => 'datetime',
        'assigned_at' => 'datetime',
        'picked_up_at' => 'datetime',
        'in_transit_at' => 'datetime',
        'arrived_at_hub_at' => 'datetime',
        'out_for_delivery_at' => 'datetime',
        'delivered_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    // Relationships
    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function logisticsCompany()
    {
        return $this->belongsTo(LogisticsCompany::class);
    }

    public function deliveryAgent()
    {
        return $this->belongsTo(DeliveryAgent::class);
    }

    public function pickupAgent()
    {
        return $this->belongsTo(DeliveryAgent::class, 'pickup_agent_id');
    }

    public function trackingEvents()
    {
        return $this->hasMany(DeliveryTrackingEvent::class, 'order_id', 'order_id')
                    ->where('metadata->shipment_id', $this->id);
    }

    public function proofs()
    {
        return $this->hasMany(DeliveryProof::class, 'order_id', 'order_id');
    }

    // Scopes
    public function scopeForOrder($query, $orderId)
    {
        return $query->where('order_id', $orderId);
    }

    public function scopeForStore($query, $storeId)
    {
        return $query->where('store_id', $storeId);
    }

    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeInProgress($query)
    {
        return $query->whereIn('status', ['shop_preparing', 'ready_for_pickup', 'assigned_to_agent', 'picked_up', 'in_transit', 'out_for_delivery']);
    }

    public function scopeDelivered($query)
    {
        return $query->where('status', 'delivered');
    }

    // Methods
    /**
     * @param  bool  $recordEvent  Pass false when the caller writes its own
     *                             tracking event for the same moment. The order
     *                             does exactly that after syncing its shipments,
     *                             and with both writing, the customer's timeline
     *                             showed each step twice.
     */
    public function updateStatus($status, $metadata = [], bool $recordEvent = true)
    {
        $timestampField = $this->getTimestampFieldForStatus($status);

        $updateData = ['status' => $status];
        if ($timestampField) {
            $updateData[$timestampField] = now();
        }

        $this->update($updateData);

        if (! $recordEvent) {
            return;
        }

        // Only create tracking event if one doesn't exist for this status in the last minute
        // This prevents duplicate events when multiple systems update the same status
        $recentEvent = DeliveryTrackingEvent::where('order_id', $this->order_id)
            ->where('status', $status)
            ->where('created_at', '>=', now()->subMinute())
            ->exists();

        if (!$recentEvent) {
            DeliveryTrackingEvent::create([
                'order_id' => $this->order_id,
                'status' => $status,
                'description' => DeliveryTrackingEvent::getStatusLabel($status),
                'metadata' => array_merge($metadata, ['shipment_id' => $this->id]),
                'created_by_type' => 'system'
            ]);
        }
    }

    protected function getTimestampFieldForStatus($status)
    {
        $mapping = [
            'ready_for_pickup' => 'ready_at',
            'assigned_to_agent' => 'assigned_at',
            'picked_up' => 'picked_up_at',
            'in_transit' => 'in_transit_at',
            'arrived_at_hub' => 'arrived_at_hub_at',
            'out_for_delivery' => 'out_for_delivery_at',
            'delivered' => 'delivered_at'
        ];

        return $mapping[$status] ?? null;
    }

    public function assignAgent(DeliveryAgent $agent)
    {
        $this->update([
            'delivery_agent_id' => $agent->id,
            'logistics_company_id' => $agent->logistics_company_id
        ]);

        $this->updateStatus('assigned_to_agent', [
            'agent_id' => $agent->id,
            'agent_name' => $agent->name
        ]);
    }

    public static function generateTrackingNumber()
    {
        do {
            $trackingNumber = 'TRK-' . strtoupper(Str::random(10));
        } while (self::where('tracking_number', $trackingNumber)->exists());

        return $trackingNumber;
    }

    public function getEstimatedDeliveryDate()
    {
        if (!$this->estimated_delivery_days) {
            return null;
        }

        $startDate = $this->picked_up_at ?? $this->created_at;
        return $startDate->addDays($this->estimated_delivery_days);
    }
}
