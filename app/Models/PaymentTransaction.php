<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'user_id',
        'transaction_id', // Paystack transaction ID
        'reference', // Paystack reference
        'amount',
        'currency',
        'status',
        'payment_method',
        'gateway', // paystack, stripe, etc.
        'gateway_response',
        'fees',
        'authorization_code', // For recurring payments
        'customer_code', // Paystack customer code
        'paid_at',
        'failed_at',
        'failure_reason',
        'metadata'
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'fees' => 'decimal:2',
        'gateway_response' => 'array',
        'metadata' => 'array',
        'paid_at' => 'datetime',
        'failed_at' => 'datetime'
    ];

    // Payment statuses
    const STATUS_PENDING = 'pending';
    const STATUS_SUCCESS = 'success';
    const STATUS_FAILED = 'failed';
    const STATUS_ABANDONED = 'abandoned';
    const STATUS_REFUNDED = 'refunded';

    // Payment gateways
    const GATEWAY_PAYSTACK = 'paystack';
    const GATEWAY_STRIPE = 'stripe';
    const GATEWAY_FLUTTERWAVE = 'flutterwave';
    /** Payment taken outside a gateway — bank transfer, or cash on delivery. */
    const GATEWAY_MANUAL = 'manual';

    // Relationships
    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Scopes
    public function scopeSuccessful($query)
    {
        return $query->where('status', self::STATUS_SUCCESS);
    }

    public function scopeFailed($query)
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeByGateway($query, $gateway)
    {
        return $query->where('gateway', $gateway);
    }

    // Methods
    public function isSuccessful()
    {
        return $this->status === self::STATUS_SUCCESS;
    }

    public function isFailed()
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function isPending()
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function markAsSuccessful($gatewayResponse = null)
    {
        $this->update([
            'status' => self::STATUS_SUCCESS,
            'paid_at' => now(),
            'gateway_response' => $gatewayResponse,
            'failed_at' => null,
            'failure_reason' => null
        ]);

        // Update order payment status and trigger post-payment actions
        if ($this->order) {
            $this->order->update([
                'payment_status' => Order::PAYMENT_PAID,
                'payment_id' => $this->transaction_id,
                'status' => Order::STATUS_PROCESSING
            ]);

            // Reduce stock quantities
            $paystackService = app(\App\Services\PaystackService::class);
            $paystackService->reduceStock($this->order);

            // Send order placed/confirmed notifications to all parties
            try {
                $notificationService = new \App\Services\OrderNotificationService();
                $notificationService->notifyOrderPlaced($this->order->fresh());
                \Log::info("Order placed notifications sent (webhook): {$this->order->order_number}");
            } catch (\Exception $e) {
                \Log::error("Failed to send order placed notifications (webhook): " . $e->getMessage());
            }

            // Generate delivery confirmation code
            $this->order->generateDeliveryCode();

            // Send order confirmed email (for both authenticated and guest users)
            $emailRecipient = null;
            if ($this->order->user) {
                $emailRecipient = $this->order->user->email;
            } elseif (isset($this->order->shipping_address['email'])) {
                $emailRecipient = $this->order->shipping_address['email'];
            }

            if ($emailRecipient) {
                \App\Jobs\SendOrderStatusEmail::dispatch($this->order, 'confirmed');
                \Log::info("Order confirmed email queued (webhook): {$this->order->order_number}", [
                    'recipient' => $emailRecipient,
                    'is_guest' => !$this->order->user
                ]);
            } else {
                \Log::warning("Cannot send order confirmation email - no email found", [
                    'order_id' => $this->order->id,
                    'order_number' => $this->order->order_number
                ]);
            }

            \Log::info('Payment marked as successful with stock reduction', [
                'order_number' => $this->order->order_number,
                'transaction_id' => $this->transaction_id
            ]);
        }
    }

    public function markAsFailed($reason = null, $gatewayResponse = null)
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'failed_at' => now(),
            'failure_reason' => $reason,
            'gateway_response' => $gatewayResponse,
            'paid_at' => null
        ]);

        // Update order payment status
        if ($this->order) {
            $this->order->update([
                'payment_status' => Order::PAYMENT_FAILED
            ]);
        }
    }

    public function getAmountInKobo()
    {
        return $this->amount * 100; // Convert to kobo for Paystack
    }

    public static function generateReference(?string $prefix = null)
    {
        $prefix = $prefix ?: config('app.order_prefix', 'TG');

        return $prefix . '_' . time() . '_' . random_int(1000, 9999);
    }

}
