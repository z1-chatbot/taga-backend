<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StorePayout extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_id',
        'amount',
        'commission_deducted',
        'net_amount',
        'status',
        'payout_method',
        'payout_details',
        'notes',
        'processed_at',
        'payment_receipt',
        'rejection_reason',
        'requested_at'
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'commission_deducted' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'payout_details' => 'array',
        'processed_at' => 'datetime',
        'requested_at' => 'datetime'
    ];

    // Relationships
    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    // Scopes
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    // Methods
    public function markAsProcessing()
    {
        $this->update(['status' => 'processing']);
    }

    public function markAsCompleted($transactionDetails = null)
    {
        $this->update([
            'status' => 'completed',
            'processed_at' => now(),
            'payout_details' => $transactionDetails ?? $this->payout_details
        ]);
    }

    public function markAsFailed($reason = null)
    {
        $this->update([
            'status' => 'failed',
            'notes' => $reason ?? $this->notes
        ]);
    }
}
