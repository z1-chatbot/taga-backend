<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AgentEarning extends Model
{
    use HasFactory;

    protected $fillable = [
        'delivery_agent_id',
        'logistics_company_id',
        'order_id',
        'shipment_id',
        'delivery_fee',
        'agreed_rate',
        'agent_commission',
        'platform_commission',
        'commission_percentage',
        'status',
        'available_at',
        'payout_id'
    ];

    protected $casts = [
        'delivery_fee' => 'decimal:2',
        'agreed_rate' => 'decimal:2',
        'agent_commission' => 'decimal:2',
        'platform_commission' => 'decimal:2',
        'commission_percentage' => 'decimal:2',
        'available_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    // Relationships
    public function deliveryAgent()
    {
        return $this->belongsTo(DeliveryAgent::class);
    }

    public function logisticsCompany()
    {
        return $this->belongsTo(LogisticsCompany::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * The parcel this was earned carrying.
     *
     * Null on earnings written before an order could be split between
     * pharmacies, when one order meant one journey.
     */
    public function shipment()
    {
        return $this->belongsTo(OrderShipment::class);
    }

    public function payout()
    {
        return $this->belongsTo(AgentPayout::class, 'payout_id');
    }

    // Scopes
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeAvailable($query)
    {
        return $query->where('status', 'available')
                    ->where('available_at', '<=', now());
    }

    public function scopePaidOut($query)
    {
        return $query->where('status', 'paid_out');
    }

    public function scopeForAgent($query, $agentId)
    {
        return $query->where('delivery_agent_id', $agentId);
    }

    public function scopeForCompany($query, $companyId)
    {
        return $query->where('logistics_company_id', $companyId);
    }

    // Methods
    /**
     * The hold period has elapsed — make this money withdrawable.
     *
     * It has to move on the same balance that was credited when the delivery
     * completed. An earning for a company rider names both the rider and the
     * company, and this used to prefer the rider — releasing into a balance that
     * was never credited and leaving the company's held forever.
     */
    public function makeAvailable()
    {
        if ($this->status !== 'pending' || $this->available_at > now()) {
            return;
        }

        $this->update(['status' => 'available']);

        $payee = $this->logistics_company_id
            ? $this->logisticsCompany
            : $this->deliveryAgent;

        if (! $payee) {
            return;
        }

        $payee->decrement('pending_balance', $this->agent_commission);
        $payee->increment('available_balance', $this->agent_commission);
    }

    public function markAsPaidOut($payoutId)
    {
        $this->update([
            'status' => 'paid_out',
            'payout_id' => $payoutId
        ]);
    }

    /**
     * Earnings are created by DeliveryEarningsService, which is the only place
     * that knows what a delivery is worth and whether it has been paid for
     * already. This model used to carry a fifth, differing copy of that sum.
     *
     * @deprecated Call DeliveryEarningsService::creditForDelivery() instead.
     */
    public static function createFromOrder(Order $order, $commissionPercentage = null)
    {
        return app(\App\Services\DeliveryEarningsService::class)->creditForDelivery($order);
    }
}
