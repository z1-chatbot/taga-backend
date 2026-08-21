<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

class LogisticsCompany extends Authenticatable
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'description',
        'logo',
        'contact_email',
        'contact_phone',
        'admin_email',
        'admin_password',
        'service_areas',
        'pricing_structure',
        'is_active',
        'available_balance',
        'pending_balance',
        'total_earned',
        'total_paid_out'
    ];

    protected $hidden = [
        'admin_password',
    ];

    protected $casts = [
        'service_areas' => 'array',
        'pricing_structure' => 'array',
        'is_active' => 'boolean',
        'available_balance' => 'decimal:2',
        'pending_balance' => 'decimal:2',
        'total_earned' => 'decimal:2',
        'total_paid_out' => 'decimal:2'
    ];

    // Relationships
    public function deliveryAgents()
    {
        return $this->hasMany(DeliveryAgent::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    // Methods
    public function coversArea($state, $city = null)
    {
        if (!$this->service_areas || !is_array($this->service_areas)) {
            return false;
        }

        foreach ($this->service_areas as $area) {
            // Handle structured format: ['state' => 'Lagos', 'cities' => ['Ikeja', 'Yaba']]
            if (is_array($area) && isset($area['state'])) {
                if (strcasecmp(trim($area['state']), trim($state)) === 0) {
                    if ($city === null) {
                        return true;
                    }
                    if (!isset($area['cities']) || empty($area['cities'])) {
                        return true;
                    }
                    foreach ($area['cities'] as $c) {
                        if (strcasecmp(trim($c), trim($city)) === 0) {
                            return true;
                        }
                    }
                }
            }
            // Handle simple string format: ['Lagos', 'Cross River', 'Calabar']
            elseif (is_string($area)) {
                if (strcasecmp(trim($area), trim($state)) === 0) {
                    return true;
                }
                if ($city && strcasecmp(trim($area), trim($city)) === 0) {
                    return true;
                }
            }
        }

        return false;
    }

    public function getAvailableAgents($state, $city = null)
    {
        return $this->deliveryAgents()
            ->where('status', 'available')
            ->get()
            ->filter(function ($agent) use ($state, $city) {
                return $agent->coversArea($state, $city);
            });
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

    public function invitations()
    {
        return $this->hasMany(AgentInvitation::class);
    }

    public function shipments()
    {
        return $this->hasMany(OrderShipment::class);
    }

    // New methods
    public function inviteAgent($email, $phone, $name, $metadata = [])
    {
        return AgentInvitation::createInvitation(
            $this->id,
            $email,
            $phone,
            $name,
            $metadata
        );
    }

    public function getAvailableBalance()
    {
        return $this->available_balance;
    }

    public function getPendingBalance()
    {
        return $this->pending_balance;
    }

    public function canRequestPayout()
    {
        $minimumAmount = DeliverySetting::getValue('minimum_payout_amount', 5000);
        return $this->available_balance >= $minimumAmount;
    }

    public function requestPayout($amount, $bankDetails = null)
    {
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
            'logistics_company_id' => $this->id,
            'payout_type' => 'logistics_company',
            'amount' => $amount,
            'status' => 'pending',
            'bank_details' => $bankDetails ?? [
                'bank_name' => $this->bank_name,
                'account_number' => $this->account_number,
                'account_name' => $this->account_name
            ]
        ]);
    }

    public function getTotalActiveAgents()
    {
        return $this->deliveryAgents()
            ->where('status', '!=', 'suspended')
            ->count();
    }

    public function getTotalDeliveries()
    {
        return $this->orders()->where('status', 'delivered')->count();
    }

    public function getAverageRating()
    {
        return $this->deliveryAgents()->avg('rating');
    }
}
