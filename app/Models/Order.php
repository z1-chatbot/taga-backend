<?php

namespace App\Models;

use App\Exceptions\InvalidStatusTransitionException;
use App\Exceptions\PrescriptionNotClearedException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'session_id',
        'order_number',
        'status',
        'subtotal',
        'tax_amount',
        'shipping_amount',
        'discount_amount',
        'coupon_discount',
        'sale_discount',
        'total_amount',
        'currency',
        'payment_status',
        'payment_method',
        'payment_id',
        'coupon_id',
        'coupon_code',
        'sale_event_id',
        'sale_event_name',
        // Delivery management
        'logistics_company_id',
        'delivery_agent_id',
        'store_id',
        'delivery_type',
        'pickup_location',
        // Pay on delivery
        'is_pay_on_delivery',
        'cod_fee',
        // Shipping zone
        'shipping_zone_id',
        'calculated_shipping_fee',
        // Addresses
        'shipping_address',
        'billing_address',
        'notes',
        // Timestamps
        'shipped_at',
        'delivered_at',
        'cancelled_at',
        'refunded_at',
        'assigned_at',
        'picked_up_at',
        'out_for_delivery_at',
        // Tracking
        'tracking_number',
        'delivery_code',
        'delivery_notes',
        /*
         * The per-order token behind the emailed rider link. It was missing
         * here, so DeliveryAssignedNotification's
         * $order->update(['delivery_access_token' => ...]) was silently
         * discarded by mass-assignment protection and the column stayed null —
         * every token link 404'd at the lookup.
         */
        'delivery_access_token',
        // Set from the order's Rx line items; gates dispatch.
        'requires_prescription',
        'prescription_status'
    ];

    protected $casts = [
        'shipping_address' => 'array',
        'billing_address' => 'array',
        'pickup_location' => 'array',
        'delivery_notes' => 'array',
        'is_pay_on_delivery' => 'boolean',
        'requires_prescription' => 'boolean',
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'shipping_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'coupon_discount' => 'decimal:2',
        'sale_discount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'cod_fee' => 'decimal:2',
        'calculated_shipping_fee' => 'decimal:2',
        'shipped_at' => 'datetime',
        'delivered_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'refunded_at' => 'datetime',
        'assigned_at' => 'datetime',
        'picked_up_at' => 'datetime',
        'out_for_delivery_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    // Order statuses
    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_READY_FOR_PICKUP = 'ready_for_pickup';
    const STATUS_ASSIGNED_TO_AGENT = 'assigned_to_agent';
    const STATUS_SHIPPED = 'shipped';
    const STATUS_DELIVERED = 'delivered';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_REFUNDED = 'refunded';

    // Payment statuses
    const PAYMENT_PENDING = 'pending';
    const PAYMENT_PAID = 'paid';
    const PAYMENT_FAILED = 'failed';
    const PAYMENT_REFUNDED = 'refunded';

    /**
     * Statuses that mean the medicine is on its way to, or with, the customer.
     */
    public const DISPATCH_STATUSES = [
        self::STATUS_READY_FOR_PICKUP,
        self::STATUS_ASSIGNED_TO_AGENT,
        self::STATUS_SHIPPED,
        'picked_up',
        'arrived_at_hub',
        'in_transit',
        'out_for_delivery',
        self::STATUS_DELIVERED,
    ];

    /**
     * How far along each status is. Fulfilment only ever moves up this scale.
     *
     * 'shipped' shares a rank with 'picked_up': it is the admin console's word
     * for the same moment — the parcel has left the pharmacy — and the two are
     * written by different actors for the same event.
     */
    public const STATUS_RANK = [
        self::STATUS_PENDING => 0,
        self::STATUS_PROCESSING => 1,
        self::STATUS_READY_FOR_PICKUP => 2,
        self::STATUS_ASSIGNED_TO_AGENT => 3,
        'picked_up' => 4,
        self::STATUS_SHIPPED => 4,
        'arrived_at_hub' => 5,
        'in_transit' => 6,
        'out_for_delivery' => 7,
        self::STATUS_DELIVERED => 8,
    ];

    /**
     * Statuses an order can drop into from anywhere, rather than progress to.
     */
    public const TERMINAL_STATUSES = [
        self::STATUS_CANCELLED,
        self::STATUS_REFUNDED,
    ];

    /**
     * Set to allow one deliberate backwards correction, e.g.
     *
     *     $order->allowStatusRegression()->update(['status' => 'processing']);
     *
     * Reset after every save so it cannot leak into the next one.
     */
    protected bool $allowRegression = false;

    public function allowStatusRegression(bool $allow = true): static
    {
        $this->allowRegression = $allow;

        return $this;
    }

    /**
     * Two invariants, enforced at the model rather than at each call site.
     *
     * **The dispatch gate.** Under the pay-now-review-before-dispatch flow an
     * order can be paid for while its prescription is still being reviewed, so
     * this is the only thing keeping unapproved medicine from reaching a
     * customer. At least six places move an order toward dispatch — the admin
     * console, the store owner, agent assignment, the rider portal, the
     * logistics portal and the delivery-code confirmation — and a guard written
     * into one controller covers only that controller.
     *
     * **Forward-only progression.** Nothing stopped an order going backwards: an
     * admin could put a delivered order back to pending, and because the admin
     * console and the rider portal wrote the same column independently, marking
     * an order shipped and then having the rider confirm pickup moved it from
     * 'shipped' back to 'picked_up'. That rewrites the customer's tracking
     * timeline and destroys the audit trail. Corrections are still possible, but
     * they have to be asked for explicitly.
     */
    protected static function booted(): void
    {
        static::updating(function (Order $order) {
            if (! $order->isDirty('status')) {
                return;
            }

            $previous = $order->getOriginal('status');
            $next = $order->status;

            if (in_array($next, self::DISPATCH_STATUSES, true) && ! $order->isClearedForDispatch()) {
                Log::warning('Blocked dispatch of an order with an uncleared prescription', [
                    'order_id' => $order->id,
                    'attempted_status' => $next,
                    'prescription_status' => $order->prescription_status,
                ]);

                throw new PrescriptionNotClearedException(
                    "Order {$order->order_number} contains prescription medicine that has not been approved "
                        ."(prescription status: {$order->prescription_status})."
                );
            }

            $order->guardForwardProgress($previous, $next);
        });

        static::updated(function (Order $order) {
            // Cancelling has consequences beyond the column: the reserved stock
            // has to go back on the shelf and the rider has to be released.
            // Only the prescription-rejection path did either, so an order
            // cancelled from the admin console left phantom out-of-stock
            // products and a rider marked busy forever.
            if ($order->wasChanged('status')
                && $order->status === self::STATUS_CANCELLED
                && ! in_array($order->getOriginal('status'), self::TERMINAL_STATUSES, true)) {
                $order->releaseOnCancellation();
            }

            // Delivery has consequences too: somebody carried the parcel and is
            // owed for it. Two admin routes marked orders delivered and credited
            // nobody, so putting it here means no path can forget. The service
            // itself refuses to pay for the same order twice.
            if ($order->wasChanged('status')
                && $order->status === self::STATUS_DELIVERED
                && $order->getOriginal('status') !== self::STATUS_DELIVERED) {
                $order->createAgentEarning();
            }

            $order->allowRegression = false;
        });
    }

    /**
     * Puts stock back and frees the assigned rider.
     *
     * Safe to reach from any cancellation path; the caller does not need to
     * know which of them already ran.
     */
    public function releaseOnCancellation(): void
    {
        foreach ($this->items()->with('product')->get() as $item) {
            if ($item->product) {
                $item->product->increment('stock_quantity', $item->quantity);
            }
        }

        $agent = $this->deliveryAgent;

        if ($agent) {
            $stillWorking = $agent->shipments()
                ->whereNotIn('status', ['delivered', 'cancelled', 'returned', 'failed'])
                ->where('order_id', '!=', $this->id)
                ->exists();

            if (! $stillWorking) {
                $agent->update(['status' => 'available']);
            }
        }

        Log::info('Order cancelled: stock restored and rider released', [
            'order_id' => $this->id,
            'agent_id' => $agent?->id,
        ]);
    }

    /**
     * The shipment status matching an order status.
     *
     * The two vocabularies overlap but are not identical: the order says
     * 'processing' where the shipment says 'shop_preparing', and 'shipped' has
     * no shipment equivalent because a shipment records the physical leg —
     * 'picked_up'. Statuses absent here (pending, cancelled, refunded) leave
     * the shipment alone.
     */
    public const SHIPMENT_STATUS_FOR_ORDER = [
        self::STATUS_PROCESSING => 'shop_preparing',
        self::STATUS_READY_FOR_PICKUP => 'ready_for_pickup',
        self::STATUS_ASSIGNED_TO_AGENT => 'assigned_to_agent',
        self::STATUS_SHIPPED => 'picked_up',
        'picked_up' => 'picked_up',
        'arrived_at_hub' => 'arrived_at_hub',
        'in_transit' => 'in_transit',
        'out_for_delivery' => 'out_for_delivery',
        self::STATUS_DELIVERED => 'delivered',
    ];

    /**
     * Brings this order's shipments up to its own status.
     *
     * The rider's portal and the logistics dashboard read the shipment, not the
     * order, so an admin marking an order shipped used to leave both of them
     * still showing it as merely assigned. Worse, the rider would then confirm
     * pickup and drag the order back down from 'shipped' to 'picked_up'.
     *
     * Only ever moves a shipment forward, and never past delivered.
     */
    public function syncShipmentsToStatus(): void
    {
        $target = self::SHIPMENT_STATUS_FOR_ORDER[$this->status] ?? null;

        if (! $target) {
            return;
        }

        foreach ($this->shipments()->get() as $shipment) {
            if (in_array($shipment->status, ['delivered', 'failed', 'returned'], true)) {
                continue;
            }

            if ($shipment->status === $target) {
                continue;
            }

            // No tracking event here: the caller writes one for this same
            // status change, with better wording, immediately afterwards.
            $shipment->updateStatus($target, [
                'updated_by' => 'order_sync',
                'order_status' => $this->status,
            ], recordEvent: false);
        }
    }

    /**
     * Refuses a status change that would move fulfilment backwards.
     */
    protected function guardForwardProgress(?string $previous, string $next): void
    {
        if ($this->allowRegression) {
            Log::info('Order status moved backwards deliberately', [
                'order_id' => $this->id,
                'from' => $previous,
                'to' => $next,
            ]);

            return;
        }

        // Cancelling or refunding is an exit, not a step back — always allowed,
        // except that a delivered order can only become refunded.
        if (in_array($next, self::TERMINAL_STATUSES, true)) {
            if ($previous === self::STATUS_DELIVERED && $next === self::STATUS_CANCELLED) {
                throw new InvalidStatusTransitionException(
                    "Order {$this->order_number} has already been delivered and cannot be cancelled. Refund it instead."
                );
            }

            return;
        }

        // Leaving a terminal state needs the explicit override — reviving a
        // cancelled order by accident would put stock and money out of step.
        if (in_array($previous, self::TERMINAL_STATUSES, true)) {
            throw new InvalidStatusTransitionException(
                "Order {$this->order_number} is {$previous} and cannot be moved back into fulfilment."
            );
        }

        $from = self::STATUS_RANK[$previous] ?? null;
        $to = self::STATUS_RANK[$next] ?? null;

        if ($from === null || $to === null || $to >= $from) {
            return;
        }

        Log::warning('Blocked a backwards order status change', [
            'order_id' => $this->id,
            'from' => $previous,
            'to' => $next,
        ]);

        throw new InvalidStatusTransitionException(
            "Order {$this->order_number} is already {$previous} and cannot go back to {$next}."
        );
    }

    // Relationships
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function prescriptions()
    {
        return $this->hasMany(Prescription::class);
    }

    /**
     * Whether this order is cleared to be dispatched.
     *
     * An order carrying prescription-only items must not ship until every one of
     * those prescriptions has been approved.
     */
    public function isClearedForDispatch(): bool
    {
        if (! $this->requires_prescription) {
            return true;
        }

        return $this->prescription_status === 'approved';
    }

    public function coupon()
    {
        return $this->belongsTo(Coupon::class);
    }

    public function saleEvent()
    {
        return $this->belongsTo(SaleEvent::class);
    }

    public function paymentTransactions()
    {
        return $this->hasMany(PaymentTransaction::class);
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

    public function shippingZone()
    {
        return $this->belongsTo(ShippingZone::class);
    }

    public function shipments()
    {
        return $this->hasMany(OrderShipment::class);
    }

    public function trackingEvents()
    {
        return $this->hasMany(DeliveryTrackingEvent::class);
    }

    public function deliveryProofs()
    {
        return $this->hasMany(DeliveryProof::class);
    }

    public function agentEarning()
    {
        return $this->hasOne(AgentEarning::class);
    }

    // Scopes
    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    public function scopeByPaymentStatus($query, $paymentStatus)
    {
        return $query->where('payment_status', $paymentStatus);
    }

    public function scopeRecent($query)
    {
        return $query->orderBy('created_at', 'desc');
    }

    // Accessors
    public function getItemCountAttribute()
    {
        return $this->items()->sum('quantity');
    }

    public function getStatusLabelAttribute()
    {
        return ucfirst(str_replace('_', ' ', $this->status));
    }

    public function getPaymentStatusLabelAttribute()
    {
        return ucfirst(str_replace('_', ' ', $this->payment_status));
    }

    /**
     * Get customer name from user or shipping address
     * This ensures we can still display customer info even if user is deleted
     */
    public function getCustomerNameAttribute()
    {
        if ($this->user) {
            return $this->user->name;
        }
        
        // Fallback to shipping address if user is deleted
        return $this->shipping_address['name'] ?? 'Deleted User';
    }

    /**
     * Get customer email from user or shipping address
     */
    public function getCustomerEmailAttribute()
    {
        if ($this->user) {
            return $this->user->email;
        }
        
        // Fallback to shipping address if user is deleted
        return $this->shipping_address['email'] ?? 'N/A';
    }

    /**
     * Get customer phone from user or shipping address
     */
    public function getCustomerPhoneAttribute()
    {
        if ($this->user) {
            return $this->user->phone;
        }
        
        // Fallback to shipping address if user is deleted
        return $this->shipping_address['phone'] ?? 'N/A';
    }

    // Methods

    // Removed: a second, unused order-number generator that produced a
    // different format ('PG-...') from the one checkout actually calls. Order
    // numbers come from OrderController::generateOrderNumber().

    /**
     * Generate a unique 6-digit delivery confirmation code.
     * Customer must provide this code to the delivery agent to receive their package.
     */
    public function generateDeliveryCode()
    {
        do {
            $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        } while (self::where('delivery_code', $code)->where('status', '!=', 'delivered')->exists());

        $this->update(['delivery_code' => $code]);
        return $code;
    }

    public function assignToDeliveryAgent(DeliveryAgent $agent)
    {
        $this->update([
            'delivery_agent_id' => $agent->id,
            'logistics_company_id' => $agent->logistics_company_id,
            'assigned_at' => now()
        ]);

        $agent->assignOrder($this);
    }

    public function markAsPickedUp()
    {
        $this->update([
            'picked_up_at' => now(),
            'status' => 'picked_up'
        ]);
    }

    public function markAsArrivedAtHub()
    {
        $this->update([
            'status' => 'arrived_at_hub'
        ]);
    }

    public function markAsOutForDelivery()
    {
        $this->update([
            'out_for_delivery_at' => now(),
            'status' => 'out_for_delivery'
        ]);
    }

    public function markAsDelivered($deliveryNotes = null)
    {
        $this->update([
            'status' => 'delivered',
            'delivered_at' => now(),
            'delivery_notes' => $deliveryNotes
        ]);

        // If pay on delivery, mark payment as received
        if ($this->is_pay_on_delivery) {
            $this->update(['payment_status' => 'paid']);
        }

        // Update delivery agent status
        if ($this->deliveryAgent) {
            $this->deliveryAgent->completeDelivery($this);
        }

        // Crediting the courier happens in the updated hook, so that a path
        // which sets the status directly is covered as well.
    }

    public function calculateShippingFee()
    {
        if ($this->free_shipping || $this->delivery_type !== 'home_delivery') {
            return 0;
        }

        $shippingAddress = $this->shipping_address;
        $state = $shippingAddress['state'] ?? null;
        $city = $shippingAddress['city'] ?? null;

        if (!$state) {
            return $this->shipping_amount ?? 0;
        }

        $zone = ShippingZone::findByLocation($state, $city);
        
        if (!$zone) {
            return $this->shipping_amount ?? 0;
        }

        $fee = $zone->calculateShippingFee();
        
        $this->update([
            'shipping_zone_id' => $zone->id,
            'calculated_shipping_fee' => $fee,
            'shipping_amount' => $fee
        ]);

        return $fee;
    }

    public function calculateTotals()
    {
        $subtotal = $this->items()->sum(function ($item) {
            return $item->quantity * $item->price;
        });

        $this->subtotal = $subtotal;
        $this->tax_amount = $subtotal * 0.075; // 7.5% VAT
        
        // Calculate shipping if not already set
        if (!$this->shipping_amount) {
            $this->calculateShippingFee();
        }
        
        // Add COD fee if applicable
        $codFee = $this->is_pay_on_delivery ? $this->cod_fee : 0;
        
        $this->total_amount = $this->subtotal + $this->tax_amount + $this->shipping_amount + $codFee - $this->discount_amount;
        
        return $this;
    }

    public function createShipments()
    {
        $service = app(\App\Services\OrderShipmentService::class);
        return $service->createShipmentsForOrder($this);
    }

    public function addTrackingEvent($status, $description, $metadata = [])
    {
        return DeliveryTrackingEvent::create([
            'order_id' => $this->id,
            'status' => $status,
            'description' => $description,
            'metadata' => $metadata,
            'created_by_type' => 'system'
        ]);
    }

    /**
     * Create tracking event based on order status change
     */
    public function createTrackingEventForStatus($newStatus, $oldStatus = null)
    {
        $statusEventMap = [
            'pending' => [
                'status' => DeliveryTrackingEvent::STATUS_ORDER_CONFIRMED,
                'description' => 'Order confirmed and payment received'
            ],
            'processing' => [
                'status' => DeliveryTrackingEvent::STATUS_SHOP_PREPARING,
                'description' => 'Store is preparing your order'
            ],
            'ready_for_pickup' => [
                'status' => DeliveryTrackingEvent::STATUS_READY_FOR_PICKUP,
                'description' => 'Order is ready for pickup by delivery partner'
            ],
            'shipped' => [
                'status' => DeliveryTrackingEvent::STATUS_PICKED_UP,
                'description' => 'Package has been picked up and is in transit'
            ],
            'delivered' => [
                'status' => DeliveryTrackingEvent::STATUS_DELIVERED,
                'description' => 'Order has been delivered successfully'
            ],
        ];

        if (isset($statusEventMap[$newStatus])) {
            $eventData = $statusEventMap[$newStatus];
            return $this->addTrackingEvent(
                $eventData['status'],
                $eventData['description'],
                ['order_status' => $newStatus, 'previous_status' => $oldStatus]
            );
        }

        return null;
    }

    /**
     * Credit the courier for this delivery.
     *
     * The calculation and the guard against paying twice both live in
     * DeliveryEarningsService, which every portal now calls — this is here so a
     * path that only reaches markAsDelivered() still pays somebody.
     */
    public function createAgentEarning()
    {
        if (! $this->delivery_agent_id && ! $this->logistics_company_id) {
            return null;
        }

        return app(\App\Services\DeliveryEarningsService::class)->creditForDelivery($this);
    }

    public function getTrackingHistory()
    {
        return $this->trackingEvents()->orderBy('created_at', 'asc')->get();
    }

    public function getCurrentTrackingStatus()
    {
        return $this->trackingEvents()->latest()->first();
    }
}
