<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class DeliveryAgent extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'logistics_company_id',
        'name',
        'email',
        'phone',
        'password',
        'profile_photo',
        'vehicle_type',
        'vehicle_number',
        'license_number',
        'bank_name',
        'account_number',
        'account_name',
        'service_areas',
        'status',
        'is_verified',
        'verified_at',
        'last_active_at',
        'rating',
        'total_deliveries',
        'pending_balance',
        'available_balance',
        'total_earned',
        'total_paid_out'
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'service_areas' => 'array',
        'rating' => 'decimal:2',
        'total_deliveries' => 'integer',
        'pending_balance' => 'decimal:2',
        'available_balance' => 'decimal:2',
        'total_earned' => 'decimal:2',
        'total_paid_out' => 'decimal:2',
        'is_verified' => 'boolean',
        'verified_at' => 'datetime',
        'last_active_at' => 'datetime'
    ];

    // Relationships
    public function logisticsCompany()
    {
        return $this->belongsTo(LogisticsCompany::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function activeOrders()
    {
        return $this->hasMany(Order::class)
            ->whereIn('status', ['processing', 'shipped'])
            ->whereNotNull('assigned_at');
    }

    // Scopes
    public function scopeAvailable($query)
    {
        return $query->where('status', 'available');
    }

    public function scopeByCompany($query, $companyId)
    {
        return $query->where('logistics_company_id', $companyId);
    }

    // Methods
    public function coversArea($state, $city = null)
    {
        if (!$this->service_areas) {
            return false;
        }

        foreach ($this->service_areas as $area) {
            // Handle structured format: ['state' => 'Lagos', 'cities' => ['Ikeja', 'Yaba']]
            if (is_array($area) && isset($area['state'])) {
                if (strtolower($area['state']) === strtolower($state)) {
                    // If no city specified, agent covers the whole state
                    if ($city === null) {
                        return true;
                    }
                    
                    // If cities array is empty or not set, agent covers all cities in the state
                    if (!isset($area['cities']) || empty($area['cities'])) {
                        return true;
                    }
                    
                    // Check if specific city is covered
                    if (in_array(strtolower($city), array_map('strtolower', $area['cities']))) {
                        return true;
                    }
                }
            }
            // Handle simple string format: ['Lagos', 'Ikeja', 'Yaba']
            else if (is_string($area)) {
                // Check if the area matches the state or city
                if (strtolower($area) === strtolower($state)) {
                    return true;
                }
                
                if ($city && strtolower($area) === strtolower($city)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function assignOrder(Order $order)
    {
        $order->update([
            'delivery_agent_id' => $this->id,
            'assigned_at' => now()
        ]);

        $this->update(['status' => 'busy']);
        $this->increment('total_deliveries');
    }

    public function completeDelivery(Order $order)
    {
        // Check if agent has other active orders
        $activeCount = $this->activeOrders()->where('id', '!=', $order->id)->count();
        
        if ($activeCount === 0) {
            $this->update(['status' => 'available']);
        }
    }

    public function updateRating()
    {
        // Calculate average rating from completed deliveries
        $completedOrders = $this->orders()
            ->where('status', 'delivered')
            ->whereNotNull('delivery_rating')
            ->get();

        if ($completedOrders->count() > 0) {
            $avgRating = $completedOrders->avg('delivery_rating');
            $this->update(['rating' => round($avgRating, 2)]);
        }
    }

    // New relationships
    public function earnings()
    {
        return $this->hasMany(AgentEarning::class);
    }

    public function payouts()
    {
        return $this->hasMany(AgentPayout::class);
    }

    public function documents()
    {
        return $this->hasMany(AgentDocument::class);
    }

    public function deliveryProofs()
    {
        return $this->hasMany(DeliveryProof::class);
    }

    public function shipments()
    {
        return $this->hasMany(OrderShipment::class);
    }

    // New methods
    public function checkVerificationStatus()
    {
        $requiredDocs = AgentDocument::getRequiredDocuments();
        $approvedDocs = $this->documents()
            ->approved()
            ->whereIn('document_type', $requiredDocs)
            ->pluck('document_type')
            ->toArray();

        $allApproved = count(array_diff($requiredDocs, $approvedDocs)) === 0;

        if ($allApproved && !$this->is_verified) {
            $this->update([
                'is_verified' => true,
                'verified_at' => now()
            ]);
        } elseif (!$allApproved && $this->is_verified) {
            $this->update([
                'is_verified' => false,
                'verified_at' => null
            ]);
        }
    }

    public function getAvailableBalance()
    {
        return $this->available_balance;
    }

    public function getPendingBalance()
    {
        return $this->pending_balance;
    }

    /**
     * Joining a company gives up any rate agreed with them personally.
     *
     * A rider under a company is not a payee — the company settles with them
     * directly, so they have no earnings here and cannot request a payout. A
     * personal rate left behind would be a number nobody is paid against, and
     * it would silently come back into force if they ever went independent
     * again, on terms agreed for a different arrangement.
     */
    protected static function booted(): void
    {
        static::updated(function (self $agent) {
            if ($agent->wasChanged('logistics_company_id') && ! $agent->isPaidDirectly()) {
                ShippingRate::where('delivery_agent_id', $agent->id)->delete();
            }
        });
    }

    /**
     * Whether this rider is paid by the platform at all.
     *
     * A rider signed up under a logistics company is that company's employee.
     * The platform settles with the company for the whole delivery and the
     * company pays its own riders however it chooses, so a company rider has no
     * balance here and nothing to withdraw. Only an independent rider does.
     */
    public function isPaidDirectly(): bool
    {
        return $this->logistics_company_id === null;
    }

    public function canRequestPayout()
    {
        if (! $this->isPaidDirectly()) {
            return false;
        }

        $minimumAmount = DeliverySetting::getValue('minimum_payout_amount', 5000);
        return $this->available_balance >= $minimumAmount;
    }

    public function requestPayout($amount, $bankDetails = null)
    {
        if (! $this->isPaidDirectly()) {
            throw new \Exception(
                'Your earnings are settled with '.($this->logisticsCompany->name ?? 'your company').', who pays you directly.'
            );
        }

        if ($amount > $this->available_balance) {
            throw new \Exception('Insufficient balance');
        }

        $minimumAmount = DeliverySetting::getValue('minimum_payout_amount', 5000);
        if ($amount < $minimumAmount) {
            throw new \Exception("Minimum payout amount is ₦{$minimumAmount}");
        }

        // Hold the amount from available balance to prevent double-withdrawal
        $this->decrement('available_balance', $amount);

        return AgentPayout::create([
            'delivery_agent_id' => $this->id,
            'payout_type' => 'agent',
            'amount' => $amount,
            'status' => 'pending',
            'bank_details' => $bankDetails ?? [
                'bank_name' => $this->bank_name,
                'account_number' => $this->account_number,
                'account_name' => $this->account_name
            ]
        ]);
    }
}
