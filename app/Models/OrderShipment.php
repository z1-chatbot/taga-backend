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
        'pickup_group',
        'tracking_number',
        'delivery_code',
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

    /**
     * The status ranks, least to most advanced.
     *
     * Used to answer "has every parcel on this order reached this point yet",
     * which is the only safe basis for moving the order itself: one rider
     * delivering their parcel does not make the order delivered when a second
     * pharmacy has not even dispatched.
     */
    public const STATUS_RANK = [
        'pending' => 0,
        'shop_preparing' => 1,
        'ready_for_pickup' => 2,
        'assigned_to_agent' => 3,
        'picked_up' => 4,
        'arrived_at_hub' => 5,
        'in_transit' => 6,
        'out_for_delivery' => 7,
        'delivered' => 8,
    ];

    /** Statuses that end a shipment's journey without delivering it. */
    public const SETTLED_STATUSES = ['failed', 'returned', 'cancelled'];

    /**
     * Where a parcel is collected from, as a grouping key.
     *
     * Two pharmacies in the same city are one rider's round: they are collected
     * together, driven together, and charged once. State alone is too coarse —
     * Ikeja and Epe are both Lagos and a hundred kilometres apart — and a
     * street address is too fine to ever match. City is the line.
     *
     * Null when the shop has no city on file. That parcel is then handled on
     * its own, which is the safe answer: grouping on a blank would sweep every
     * addressless pharmacy in the basket into one imaginary run.
     */
    public static function pickupGroupFor(?Store $store): ?string
    {
        $state = trim((string) ($store->state ?? ''));
        $city = trim((string) ($store->city ?? ''));

        if ($state === '' || $city === '') {
            return null;
        }

        return mb_strtolower($state.'|'.$city);
    }

    /**
     * The other parcels on this order collected in the same round, this one
     * included.
     *
     * An ungrouped parcel is a run of one — never a run of "everything else
     * that is also ungrouped", which is why the null case is handled here
     * rather than left to a `where` on a null column.
     */
    public function run()
    {
        $query = static::where('order_id', $this->order_id);

        return $this->pickup_group === null
            ? $query->whereKey($this->id)
            : $query->where('pickup_group', $this->pickup_group);
    }

    public function rank(): int
    {
        return self::STATUS_RANK[$this->status] ?? 0;
    }

    /**
     * The confirmation code for this parcel, minted once and then left alone.
     *
     * Idempotent for the same reason Order::ensureDeliveryCode() is: re-minting
     * would invalidate a code the customer has already been emailed and is
     * holding at their door.
     */
    public function ensureDeliveryCode(): string
    {
        if ($this->delivery_code) {
            return $this->delivery_code;
        }

        do {
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        } while (self::where('delivery_code', $code)->where('status', '!=', 'delivered')->exists());

        $this->update(['delivery_code' => $code]);

        return $code;
    }

    /**
     * Whether the code a rider typed releases this parcel.
     *
     * Falls back to the order's code only when the shipment has none of its
     * own — an order placed before parcels carried their own codes. Once a
     * shipment has a code, the order's no longer opens it, which is the whole
     * point: on a split order the other pharmacy's rider must not be able to
     * close this delivery.
     */
    public function verifyDeliveryCode(?string $supplied): bool
    {
        $expected = $this->delivery_code ?: $this->order?->delivery_code;

        if (! $expected) {
            // Nothing to check against. Historically this meant the code was
            // never minted, and refusing here would strand the parcel.
            return true;
        }

        return is_string($supplied) && hash_equals($expected, trim($supplied));
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
