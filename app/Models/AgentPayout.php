<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AgentPayout extends Model
{
    use HasFactory;

    protected $fillable = [
        'delivery_agent_id',
        'logistics_company_id',
        'payout_type',
        'amount',
        'status',
        'payment_method',
        'bank_details',
        'reference_number',
        'notes',
        'approved_by',
        'approved_at',
        'completed_at'
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'bank_details' => 'array',
        'approved_at' => 'datetime',
        'completed_at' => 'datetime',
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

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function earnings()
    {
        return $this->hasMany(AgentEarning::class, 'payout_id');
    }

    // Scopes
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
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
    public function approve($userId)
    {
        $this->update([
            'status' => 'approved',
            'approved_by' => $userId,
            'approved_at' => now()
        ]);
    }

    public function markAsProcessing()
    {
        $this->update(['status' => 'processing']);
    }

    public function complete($referenceNumber = null)
    {
        $this->update([
            'status' => 'completed',
            'reference_number' => $referenceNumber,
            'completed_at' => now()
        ]);

        // Update total_paid_out (available_balance was already held at request time)
        if ($this->delivery_agent_id) {
            $this->deliveryAgent->increment('total_paid_out', $this->amount);
        } elseif ($this->logistics_company_id) {
            $this->logisticsCompany->increment('total_paid_out', $this->amount);
        }
    }

    public function cancel($reason = null)
    {
        $this->update([
            'status' => 'cancelled',
            'notes' => $reason
        ]);

        // Return held balance
        if ($this->delivery_agent_id) {
            $this->deliveryAgent->increment('available_balance', $this->amount);
        } elseif ($this->logistics_company_id) {
            $this->logisticsCompany->increment('available_balance', $this->amount);
        }
    }

    public function fail($reason = null)
    {
        $this->update([
            'status' => 'failed',
            'notes' => $reason
        ]);

        // Return held balance
        if ($this->delivery_agent_id) {
            $this->deliveryAgent->increment('available_balance', $this->amount);
        } elseif ($this->logistics_company_id) {
            $this->logisticsCompany->increment('available_balance', $this->amount);
        }
    }
}
